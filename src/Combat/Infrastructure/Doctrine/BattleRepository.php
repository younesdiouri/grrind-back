<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Doctrine;

use App\Combat\Application\ListBattles;
use App\Combat\Domain\Battle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
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

    /**
     * L'historique du joueur, du combat le plus récent au plus ancien — le pendant exact de
     * {@see \App\Training\Infrastructure\Doctrine\WorkoutRepository::history()}.
     *
     * **L'ordre est `(foughtAt, id)`, tous les deux décroissants.** `foughtAt` seul ne suffit
     * pas à départager deux combats livrés à la même seconde — improbable pour un joueur seul,
     * mais pas pour deux appels concurrents du même compte — et l'identifiant sert de
     * départage, comme sur l'historique des workouts. `idx_combat_battle_player` porte
     * exactement ce tri depuis le #220, pour qu'aucune page n'oblige Postgres à trier à la
     * volée.
     *
     * La comparaison lexicographique s'écrit à la main pour la même raison que sur les
     * workouts : DQL n'a pas d'équivalent portable à `(a, b) < (x, y)`.
     *
     * `$take` est volontairement plus grand que `$query->limit` : l'appelant lit une ligne de
     * plus pour savoir s'il existe une page suivante, et ne la rend pas.
     *
     * @return list<Battle>
     */
    public function history(ListBattles $query, int $take): array
    {
        $builder = $this->createQueryBuilder('b')
            ->where('b.playerId = :playerId')
            ->setParameter('playerId', $query->playerId, UuidType::NAME)
            ->orderBy('b.foughtAt', 'DESC')
            ->addOrderBy('b.id', 'DESC')
            ->setMaxResults($take);

        if (null !== $query->cursor) {
            $builder
                ->andWhere('b.foughtAt < :cursorAt OR (b.foughtAt = :cursorAt AND b.id < :cursorId)')
                ->setParameter('cursorAt', $query->cursor->at)
                ->setParameter('cursorId', $query->cursor->id, UuidType::NAME);
        }

        /** @var list<Battle> $battles */
        $battles = $builder->getQuery()->getResult();

        return $battles;
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Ce qui rend le tirage de loot atomique avec l'écriture du combat (#227) : même geste
     * que {@see \App\Training\Infrastructure\Doctrine\WorkoutRepository::transactional()},
     * pour la même raison — un combat gagné dont le loot n'est pas écrit est une perte
     * silencieuse, un loot écrit sans son combat est un objet sans provenance.
     *
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
