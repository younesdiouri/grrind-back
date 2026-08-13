<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Doctrine;

use App\Training\Application\ListSessions;
use App\Training\Domain\Workout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Workout>
 */
class WorkoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workout::class);
    }

    public function add(Workout $session): void
    {
        $this->getEntityManager()->persist($session);
    }

    /**
     * Le propriétaire est une **condition de la recherche**, pas un contrôle qui suit :
     * aucun appelant ne peut oublier de vérifier à qui est la séance.
     */
    public function ofPlayer(Uuid $userId, Uuid $sessionId): ?Workout
    {
        return $this->findOneBy(['id' => $sessionId, 'userId' => $userId]);
    }

    /**
     * L'ordre est celui de l'UUID v7, triable par construction : `id < :cursor` tient
     * lieu de pagination, sans colonne d'ordre ni `OFFSET`.
     *
     * **Ce raccourci vient d'expirer.** Il tenait parce que l'identifiant se générait au
     * moment où la séance commençait ; avec l'import, dix workouts vieux de dix jours
     * reçoivent leurs UUID à la file, et l'ordre rendu devient celui de l'import et non
     * celui de la pratique. La correction est un curseur composite `(startedAt, id)`,
     * et elle appartient à #93 avec le reste de la route — la faire ici obligerait à
     * réécrire deux fois la même pagination.
     *
     * `$take` est volontairement plus grand que `$query->limit` : l'appelant lit une
     * ligne de plus pour savoir s'il existe une page suivante, et ne la rend pas.
     *
     * @return list<Workout>
     */
    public function history(ListSessions $query, int $take): array
    {
        $builder = $this->createQueryBuilder('s')
            ->where('s.userId = :userId')
            ->setParameter('userId', $query->userId, UuidType::NAME)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($take);

        if (null !== $query->discipline) {
            $builder->andWhere('s.discipline = :discipline')->setParameter('discipline', $query->discipline);
        }

        if (null !== $query->from) {
            $builder->andWhere('s.startedAt >= :from')->setParameter('from', $query->from);
        }

        if (null !== $query->to) {
            $builder->andWhere('s.startedAt <= :to')->setParameter('to', $query->to);
        }

        if (null !== $query->cursor) {
            $builder->andWhere('s.id < :cursor')->setParameter('cursor', $query->cursor, UuidType::NAME);
        }

        /** @var list<Workout> $sessions */
        $sessions = $builder->getQuery()->getResult();

        return $sessions;
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Ce qui rend l'outbox atomique : l'`INSERT` de l'événement, écrit par le transport
     * Doctrine sur cette connexion, partage le `COMMIT` de la séance.
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
