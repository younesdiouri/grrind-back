<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenSecret;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<RefreshToken>
 */
class RefreshTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RefreshToken::class);
    }

    public function ofSecret(RefreshTokenSecret $secret): ?RefreshToken
    {
        return $this->findOneBy(['tokenHash' => $secret->hash()]);
    }

    /** Résout le pointeur `successorId` : voir son docblock sur `RefreshToken`. */
    public function ofId(Uuid $id): ?RefreshToken
    {
        return $this->find($id);
    }

    public function add(RefreshToken $token): void
    {
        $this->getEntityManager()->persist($token);
    }

    /** Toute la lignée d'un appareil. Appelé à la déconnexion et sur rejeu détecté. */
    public function revokeFamily(Uuid $familyId, DateTimeImmutable $now): void
    {
        // En DQL : une famille ancienne compte des dizaines de rotations dont on n'a
        // rien à faire ici.
        $this->getEntityManager()->createQuery(
            'UPDATE '.RefreshToken::class.' t
             SET t.revokedAt = :now
             WHERE t.familyId = :family AND t.revokedAt IS NULL'
        )
            ->setParameter('now', $now)
            ->setParameter('family', $familyId, UuidType::NAME)
            ->execute();
    }

    /** Persiste la nouvelle famille à l'ouverture de session. */
    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * #364 : rotation, rejeu et logout partagent le verrou de la famille, pas du secret.
     * Aucune ligne « famille » n'existe : reprendre le verrou transactionnel déjà employé
     * par les ledgers, relâché au COMMIT/ROLLBACK. Le store Lock PostgreSQL de Symfony est
     * lié à la session, pas à cette transaction (https://symfony.com/doc/current/lock.html).
     * Une collision de hash ne fait qu'attendre deux familles sans lien ; l'espace à deux
     * clés isole ces verrous de ceux des autres modules.
     *
     * `ofSecret()` ne sert qu'à identifier la famille. Relire après le verrou avec refresh()
     * est indispensable : un find() restituerait l'instance périmée de l'identity map.
     * Le flush final lie consommation/successeur et révocation/retrait du push (#136).
     * https://www.postgresql.org/docs/17/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactionalFor(RefreshToken $presented, callable $work): mixed
    {
        $manager = $this->getEntityManager();

        return $manager->wrapInTransaction(static function () use ($manager, $presented, $work): mixed {
            $manager->getConnection()->executeStatement(
                "SELECT pg_advisory_xact_lock(hashtext('identity.refresh'), hashtext(:family))",
                ['family' => $presented->familyId()->toRfc4122()],
            );
            $manager->refresh($presented);

            return $work();
        });
    }
}
