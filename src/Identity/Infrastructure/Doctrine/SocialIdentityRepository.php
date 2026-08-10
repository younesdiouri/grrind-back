<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\SocialIdentity;
use App\Identity\Domain\SocialProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SocialIdentity>
 */
class SocialIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialIdentity::class);
    }

    public function ofSubject(SocialProvider $provider, string $subject): ?SocialIdentity
    {
        return $this->findOneBy(['provider' => $provider, 'subject' => $subject]);
    }

    public function add(SocialIdentity $identity): void
    {
        $this->getEntityManager()->persist($identity);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
