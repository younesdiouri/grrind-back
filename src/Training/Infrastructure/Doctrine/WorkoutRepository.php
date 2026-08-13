<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Doctrine;

use App\Shared\Domain\Activity\WorkoutSource;
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
     * Les couples (source, identifiant fournisseur) déjà en base parmi ceux proposés,
     * rendus sous la forme que {@see \App\Training\Application\ImportWorkoutsHandler}
     * interroge.
     *
     * Une seule requête pour tout le lot, et non une par workout : un client qui a perdu
     * son curseur renvoie deux cents séances, dont deux cents sont des doublons.
     *
     * Le filtre porte sur le seul `externalId`, et la paire se reconstitue en PHP. C'est
     * volontaire : la vraie condition est `(user_id, source, external_id)`, mais l'exprimer
     * en SQL demanderait un `IN` sur des tuples que Doctrine ne sait pas paramétrer
     * proprement. Le sur-ensemble ramené est minuscule — au pire les mêmes lignes vues sous
     * l'autre source — et la comparaison exacte se fait sur la clé rendue.
     *
     * **Ce n'est pas ce qui garantit l'unicité.** Entre ce SELECT et l'INSERT qui suit,
     * deux synchronisations concurrentes passent toutes les deux ; c'est `uniq_workout_external`
     * qui refuse la seconde, et le verrou du #89 qui les sérialise. Ici on évite d'écrire
     * des doublons évidents, on ne les rend pas impossibles.
     *
     * @param list<string> $externalIds
     *
     * @return array<string, true> indexé par "SOURCE\0EXTERNAL_ID"
     */
    public function knownProviderKeys(Uuid $userId, array $externalIds): array
    {
        if ([] === $externalIds) {
            return [];
        }

        /** @var list<array{source: WorkoutSource, externalId: string}> $rows */
        $rows = $this->createQueryBuilder('w')
            ->select('w.source', 'w.externalId')
            ->where('w.userId = :userId')
            ->andWhere('w.externalId IN (:externalIds)')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->setParameter('externalIds', $externalIds)
            ->getQuery()
            ->getResult();

        $known = [];

        foreach ($rows as $row) {
            $known[self::providerKey($row['source'], $row['externalId'])] = true;
        }

        return $known;
    }

    /**
     * La clé de dédoublonnage, écrite ici pour que le dépôt et son appelant ne puissent
     * pas en avoir deux versions. Le séparateur est un octet nul : il ne peut apparaître
     * dans aucun identifiant de fournisseur, alors qu'un tiret, si.
     */
    public static function providerKey(WorkoutSource $source, string $externalId): string
    {
        return $source->value."\0".$externalId;
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
