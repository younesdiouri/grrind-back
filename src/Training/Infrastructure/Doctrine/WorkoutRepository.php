<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Doctrine;

use App\Shared\Domain\Activity\WorkoutSource;
use App\Training\Application\ListWorkouts;
use App\Training\Domain\Workout;
use DateTimeImmutable;
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
     * Les créneaux déjà occupés par les workouts du joueur qui touchent la période donnée.
     *
     * Une seule requête pour tout le lot, comme pour les doublons : la comparaison des
     * chevauchements se fait ensuite en PHP, sur des paires de dates. Un `SELECT` par
     * candidat serait deux cents requêtes pour une resynchronisation.
     *
     * La période demandée est **élargie par l'appelant** au-delà des bornes du lot : un
     * workout déjà en base qui commence avant le lot et finit dedans le chevauche, et une
     * requête calée sur les seules bornes du lot ne le verrait pas.
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    public function busyIntervalsBetween(Uuid $userId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        /** @var list<array{startedAt: DateTimeImmutable, endedAt: DateTimeImmutable}> $rows */
        $rows = $this->createQueryBuilder('w')
            ->select('w.startedAt', 'w.endedAt')
            ->where('w.userId = :userId')
            ->andWhere('w.startedAt < :to')
            ->andWhere('w.endedAt > :from')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [$row['startedAt'], $row['endedAt']],
            $rows,
        );
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
     * L'historique du joueur, de la séance la plus récente à la plus ancienne.
     *
     * **L'ordre est celui de la pratique, `(startedAt, id)`.** Il a été celui de l'UUID v7
     * tant que l'identifiant naissait au moment où la séance commençait ; l'import a séparé
     * les deux, et dix workouts vieux de dix jours recevaient leurs UUID à la file — donc
     * l'historique suivait l'import, pas la pratique. L'identifiant reste comme
     * **départage**, parce que deux workouts peuvent commencer à la même seconde et qu'une
     * page qui s'arrêterait entre les deux les rendrait deux fois ou pas du tout.
     *
     * La comparaison lexicographique s'écrit à la main : DQL ne connaît pas les
     * comparaisons de tuples, et `(a, b) < (x, y)` n'a pas d'équivalent portable.
     *
     * `$take` est volontairement plus grand que `$query->limit` : l'appelant lit une ligne
     * de plus pour savoir s'il existe une page suivante, et ne la rend pas.
     *
     * @return list<Workout>
     */
    public function history(ListWorkouts $query, int $take): array
    {
        $builder = $this->createQueryBuilder('w')
            ->where('w.userId = :userId')
            ->setParameter('userId', $query->userId, UuidType::NAME)
            ->orderBy('w.startedAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults($take);

        if (null !== $query->discipline) {
            $builder->andWhere('w.discipline = :discipline')->setParameter('discipline', $query->discipline);
        }

        if (null !== $query->from) {
            $builder->andWhere('w.startedAt >= :from')->setParameter('from', $query->from);
        }

        if (null !== $query->to) {
            $builder->andWhere('w.startedAt <= :to')->setParameter('to', $query->to);
        }

        if (null !== $query->cursor) {
            $builder
                ->andWhere('w.startedAt < :cursorAt OR (w.startedAt = :cursorAt AND w.id < :cursorId)')
                ->setParameter('cursorAt', $query->cursor->at)
                ->setParameter('cursorId', $query->cursor->id, UuidType::NAME);
        }

        /** @var list<Workout> $workouts */
        $workouts = $builder->getQuery()->getResult();

        return $workouts;
    }

    /**
     * La fin du workout le plus récent que le joueur ait en base, ou `null` s'il n'en a
     * aucun. C'est le repère que le client donne à HealthKit ou Health Connect pour ne
     * demander que ce qui a bougé depuis.
     *
     * **Tous les workouts comptent, y compris ceux qu'on n'a pas crédités.** Un workout hors
     * fenêtre est archivé, pas ignoré : le renvoyer ne ferait que produire un
     * `ALREADY_IMPORTED` de plus. Ce que le client cherche, c'est la frontière de ce que le
     * serveur **connaît**, pas celle de ce qu'il a payé.
     */
    public function lastImportedAt(Uuid $userId): ?DateTimeImmutable
    {
        $latest = $this->createQueryBuilder('w')
            ->select('MAX(w.endedAt)')
            ->where('w.userId = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->getSingleScalarResult();

        // Doctrine rend une chaîne sur une agrégation, et `null` sur une table vide.
        return \is_string($latest) ? new DateTimeImmutable($latest) : null;
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
