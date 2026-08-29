<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Doctrine;

use App\Combat\Domain\Battle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Battle>
 */
class BattleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Battle::class);
    }

    public function add(Battle $battle): void
    {
        $this->getEntityManager()->persist($battle);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
