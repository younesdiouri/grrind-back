<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenRepository;
use App\Identity\Domain\RefreshTokenSecret;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(RefreshTokenRepository::class)]
final readonly class DoctrineRefreshTokenRepository implements RefreshTokenRepository
{
    /**
     * @var EntityRepository<RefreshToken>
     */
    private EntityRepository $repository;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->repository = $entityManager->getRepository(RefreshToken::class);
    }

    public function ofSecret(RefreshTokenSecret $secret): ?RefreshToken
    {
        return $this->repository->findOneBy(['tokenHash' => $secret->hash()]);
    }

    public function add(RefreshToken $token): void
    {
        $this->entityManager->persist($token);
    }

    public function revokeFamily(Uuid $familyId, DateTimeImmutable $now): void
    {
        // En DQL plutôt qu'en chargeant la famille : une famille ancienne peut
        // compter des dizaines de rotations, et on n'a rien à en faire ici.
        $this->entityManager->createQuery(
            'UPDATE '.RefreshToken::class.' t
             SET t.revokedAt = :now
             WHERE t.familyId = :family AND t.revokedAt IS NULL'
        )
            ->setParameter('now', $now)
            ->setParameter('family', $familyId, UuidType::NAME)
            ->execute();
    }

    public function commit(): void
    {
        $this->entityManager->flush();
    }
}
