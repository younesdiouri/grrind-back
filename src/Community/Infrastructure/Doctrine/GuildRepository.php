<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Guild;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Guild>
 */
class GuildRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guild::class);
    }

    public function add(Guild $guild): void
    {
        $this->getEntityManager()->persist($guild);
    }

    public function ofId(Uuid $id): ?Guild
    {
        return $this->find($id);
    }

    /**
     * Dissout la guilde. Les adhésions partent avec elle — `cascade: remove` sur
     * l'association, doublé du `ON DELETE CASCADE` de la colonne — et le tout dans la
     * transaction de l'appelant : une guilde supprimée dont les adhésions survivraient
     * laisserait ses membres dans une guilde qui n'existe plus, donc incapables d'en
     * rejoindre une autre à cause de l'index unique.
     */
    public function dissolve(Guild $guild): void
    {
        $this->getEntityManager()->remove($guild);
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $work());
    }
}
