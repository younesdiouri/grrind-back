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

    /**
     * Révoque toute la lignée d'un appareil. Appelé à la déconnexion, et sur
     * détection de rejeu.
     */
    public function revokeFamily(Uuid $familyId, DateTimeImmutable $now): void
    {
        // En DQL plutôt qu'en chargeant la famille : une famille ancienne peut
        // compter des dizaines de rotations, et on n'a rien à en faire ici.
        $this->getEntityManager()->createQuery(
            'UPDATE '.RefreshToken::class.' t
             SET t.revokedAt = :now
             WHERE t.familyId = :family AND t.revokedAt IS NULL'
        )
            ->setParameter('now', $now)
            ->setParameter('family', $familyId, UuidType::NAME)
            ->execute();
    }

    /**
     * Écrit les modifications en attente. La rotation touche deux jetons — celui
     * qu'on consomme et celui qu'on émet — et doit être atomique.
     */
    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
