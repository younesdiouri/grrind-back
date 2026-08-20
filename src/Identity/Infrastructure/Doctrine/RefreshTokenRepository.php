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

    /** La rotation touche deux jetons — consommé et émis — et doit être atomique. */
    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Révoquer une famille et retirer l'appareil qu'elle portait est un seul geste — voir
     * `LogOutHandler` et le rejeu détecté par `RefreshSessionHandler` (#136). Les deux DQL
     * `UPDATE`/`DELETE` qu'ils déclenchent s'exécutent hors du unit of work Doctrine ; sans
     * cette transaction explicite, rien ne les lierait l'une à l'autre.
     */
    public function transactional(callable $work): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $work());
    }
}
