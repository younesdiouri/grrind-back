<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Doctrine;

use App\Training\Domain\TrainingSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrainingSession>
 */
class TrainingSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSession::class);
    }

    public function add(TrainingSession $session): void
    {
        $this->getEntityManager()->persist($session);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
