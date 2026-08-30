<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Doctrine;

use App\Rewards\Domain\LootRoll;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Écrit et relit les lignes d'audit de tirage. **Personne ne l'appelle encore** — voir le
 * docblock de {@see LootRoll} : les deux points d'entrée qui écriront ici sont le #226
 * (import) et le #227 (combat), dans la même transaction que ce qui a causé le tirage.
 *
 * @extends ServiceEntityRepository<LootRoll>
 */
class LootRollRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LootRoll::class);
    }

    public function add(LootRoll $roll): void
    {
        $this->getEntityManager()->persist($roll);
    }

    /** Même geste que {@see \App\Combat\Infrastructure\Doctrine\BattleRepository::ofId()}. */
    public function ofId(Uuid $id): ?LootRoll
    {
        return $this->find($id);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
