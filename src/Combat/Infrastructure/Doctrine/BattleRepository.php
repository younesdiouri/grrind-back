<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Doctrine;

use App\Combat\Domain\Battle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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

    /** Même geste que {@see \App\Community\Infrastructure\Doctrine\GuildRepository::ofId()}. */
    public function ofId(Uuid $id): ?Battle
    {
        return $this->find($id);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
