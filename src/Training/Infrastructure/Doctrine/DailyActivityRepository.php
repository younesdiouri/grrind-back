<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Doctrine;

use App\Shared\Application\ActiveEnergyWindows;
use App\Shared\Domain\Activity\WorkoutSource;
use App\Training\Domain\DailyActivity;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DailyActivity>
 *
 * L'implémentation du port {@see ActiveEnergyWindows} : c'est par cette classe, et
 * uniquement par elle, que `Progression` connaît l'énergie active d'un joueur — voir le
 * docblock du port pour pourquoi cette frontière existe.
 */
class DailyActivityRepository extends ServiceEntityRepository implements ActiveEnergyWindows
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyActivity::class);
    }

    public function ofPlayerAndDay(Uuid $userId, DateTimeImmutable $day): ?DailyActivity
    {
        return $this->findOneBy(['userId' => $userId, 'day' => $day]);
    }

    /**
     * Écrit une journée, ou la remplace si elle existe déjà — la même journée envoyée deux
     * fois n'est pas un doublon, c'est une révision. Voir le docblock de {@see DailyActivity}
     * pour pourquoi c'est le geste attendu plutôt qu'une exception.
     *
     * `INSERT ... ON CONFLICT (user_id, day) DO UPDATE`, en une requête : contrairement à
     * `ProgressionSnapshotRepository::lockFor()` ou `PendingGuildActivityRepository::recordSession()`,
     * il n'y a ici ni verrou à tenir ni décision à prendre selon que la ligne existait déjà —
     * la dernière valeur envoyée doit gagner dans les deux cas, ce que `DO UPDATE` fait tout
     * seul, sans lecture préalable ni second aller-retour.
     *
     * `id` n'est pas repris à l'`UPDATE` — la ligne garde son identité d'origine à travers
     * ses révisions, seules `activeEnergyKcal`, `source`, `trust` et `updatedAt` bougent.
     */
    public function upsert(Uuid $userId, DateTimeImmutable $day, int $activeEnergyKcal, WorkoutSource $source, DateTimeImmutable $now): void
    {
        if ($activeEnergyKcal < 0) {
            throw new InvalidArgumentException(\sprintf('Une énergie active ne se mesure pas en dessous de zéro : %d reçu.', $activeEnergyKcal));
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO daily_activity (id, user_id, day, active_energy_kcal, source, trust, created_at, updated_at)
                VALUES (:id, :userId, :day, :activeEnergyKcal, :source, :trust, :now, :now)
                ON CONFLICT (user_id, day) DO UPDATE SET
                    active_energy_kcal = EXCLUDED.active_energy_kcal,
                    source = EXCLUDED.source,
                    trust = EXCLUDED.trust,
                    updated_at = EXCLUDED.updated_at
                SQL,
            [
                'id' => Uuid::v7()->toRfc4122(),
                'userId' => $userId->toRfc4122(),
                'day' => $day,
                'activeEnergyKcal' => $activeEnergyKcal,
                'source' => $source->value,
                'trust' => $source->defaultTrust()->value,
                'now' => $now,
            ],
            [
                'day' => Types::DATE_IMMUTABLE,
                'now' => Types::DATETIMETZ_IMMUTABLE,
            ],
        );
    }

    /**
     * L'implémentation du port {@see ActiveEnergyWindows} — voir son docblock pour le
     * contrat. Une seule requête, groupée par joueur, quel que soit le nombre demandé.
     *
     * **Le diviseur est `windowDays`, jamais le nombre de lignes trouvées.** Une journée
     * absente de la fenêtre compte pour zéro dans la moyenne, elle n'en réduit pas le
     * dénominateur — sans quoi un joueur qui n'a envoyé qu'un seul jour actif sur les sept
     * de la fenêtre afficherait la moyenne de *ce* jour-là, pas celle de sa semaine.
     *
     * @param list<Uuid> $userIds
     *
     * @return array<string, int>
     */
    public function averagesOf(array $userIds, DateTimeImmutable $endingOn, int $windowDays): array
    {
        $averages = array_fill_keys(array_map(static fn (Uuid $id): string => $id->toRfc4122(), $userIds), 0);

        if ([] === $userIds) {
            return $averages;
        }

        // Chaîne au format `Y-m-d` reconstruite en `DateTimeImmutable` : `day` est une
        // colonne `DATE`, sans heure ni fuseau, et seule la partie civile d'`$endingOn`
        // compte — voir le docblock du port pour qui décide de cette date.
        $endDay = new DateTimeImmutable($endingOn->format('Y-m-d'));
        $startDay = $endDay->modify(\sprintf('-%d days', $windowDays - 1));

        /** @var list<array{userId: Uuid, total: string}> $rows */
        $rows = $this->createQueryBuilder('d')
            ->select('d.userId', 'SUM(d.activeEnergyKcal) AS total')
            ->where('d.userId IN (:ids)')
            ->andWhere('d.day >= :startDay')
            ->andWhere('d.day <= :endDay')
            ->groupBy('d.userId')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $userIds))
            ->setParameter('startDay', $startDay, Types::DATE_IMMUTABLE)
            ->setParameter('endDay', $endDay, Types::DATE_IMMUTABLE)
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $averages[$row['userId']->toRfc4122()] = intdiv((int) $row['total'], $windowDays);
        }

        return $averages;
    }
}
