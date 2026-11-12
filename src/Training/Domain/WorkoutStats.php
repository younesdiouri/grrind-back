<?php

declare(strict_types=1);

namespace App\Training\Domain;

use App\Shared\Domain\Timezone;
use DateTimeImmutable;

/**
 * Projection de volume, sans XP ni jugement médical. Les sommes SQL portent sur les
 * durées mesurées, attribuées entièrement au jour du début de séance comme dans l'app.
 * On ne reçoit que des groupes jour/discipline, jamais l'historique des séances.
 */
final readonly class WorkoutStats
{
    /** @var array{seconds: int, sessions: int, activeDays: int, averageSeconds: float} */
    public array $current;
    /** @var array{seconds: int, sessions: int, activeDays: int, averageSeconds: float} */
    public array $previous;
    public ?int $change;
    public ?VolumeGrade $grade;
    /** @var list<array{date: DateTimeImmutable, seconds: int, sessions: int}> */
    public array $daily;
    /** @var list<array{from: DateTimeImmutable, to: DateTimeImmutable, seconds: int, sessions: int, activeDays: int, isFuture: bool}> */
    public array $buckets;
    /** @var list<array{discipline: string, seconds: int, sessions: int, share: float}> */
    public array $disciplines;

    /** @param list<array{date: string, discipline: string, seconds: int, sessions: int}> $rows */
    public function __construct(
        public StatsPeriod $period,
        public Timezone $timezone,
        public WorkoutStatsWindow $window,
        array $rows,
    ) {
        $startDate = $window->start->format('Y-m-d');
        $current = array_values(array_filter($rows, static fn (array $row): bool => $row['date'] >= $startDate));
        $previous = array_values(array_filter($rows, static fn (array $row): bool => $row['date'] < $startDate));
        $this->current = self::totals($current);
        $this->previous = self::totals($previous);
        $ratio = $this->previous['seconds'] > 0 ? $this->current['seconds'] / $this->previous['seconds'] : null;
        // Math.round() de l'app arrondit les demis négatifs vers +∞, contrairement à round() PHP.
        $this->change = null === $ratio ? null : (int) floor(($ratio - 1) * 100 + 0.5);
        $this->grade = match (true) {
            null === $ratio => null,
            $ratio >= 1.2 => VolumeGrade::A,
            $ratio >= 1 => VolumeGrade::B,
            $ratio >= 0.8 => VolumeGrade::C,
            $ratio >= 0.5 => VolumeGrade::D,
            default => VolumeGrade::E,
        };

        $days = [];
        $disciplines = [];
        foreach ($current as $row) {
            $date = $row['date'];
            $discipline = $row['discipline'];
            $days[$date] ??= ['seconds' => 0, 'sessions' => 0];
            $days[$date]['seconds'] += $row['seconds'];
            $days[$date]['sessions'] += $row['sessions'];
            $disciplines[$discipline] ??= ['discipline' => $discipline, 'seconds' => 0, 'sessions' => 0, 'share' => 0.0];
            $disciplines[$discipline]['seconds'] += $row['seconds'];
            $disciplines[$discipline]['sessions'] += $row['sessions'];
        }
        foreach ($disciplines as &$discipline) {
            $discipline['share'] = $this->current['seconds'] > 0 ? (float) ($discipline['seconds'] / $this->current['seconds']) : 0.0;
        }
        unset($discipline);
        usort($disciplines, static fn (array $a, array $b): int => ($b['seconds'] <=> $a['seconds']) ?: strcmp($a['discipline'], $b['discipline']));
        $this->disciplines = $disciplines;

        $daily = [];
        $buckets = [];
        // Au plus 366 jours : le coût PHP dépend du calendrier, pas du nombre de séances.
        for ($date = $window->start; $date < $window->end; $date = $date->modify('+1 day')->setTime(0, 0)) {
            $totals = $days[$date->format('Y-m-d')] ?? ['seconds' => 0, 'sessions' => 0];
            if ($date < $window->observedEnd) {
                $daily[] = ['date' => $date, ...$totals];
            }
            $key = $date->format(StatsPeriod::Year === $period ? 'Y-m' : 'Y-m-d');
            $buckets[$key] ??= [
                'from' => $date,
                'to' => $date->modify(StatsPeriod::Year === $period ? '+1 month' : '+1 day')->setTime(0, 0),
                'seconds' => 0, 'sessions' => 0, 'activeDays' => 0,
                'isFuture' => $date >= $window->observedEnd,
            ];
            $buckets[$key]['seconds'] += $totals['seconds'];
            $buckets[$key]['sessions'] += $totals['sessions'];
            $buckets[$key]['activeDays'] += $totals['sessions'] > 0 ? 1 : 0;
        }
        $this->daily = $daily;
        $this->buckets = array_values($buckets);
    }

    /**
     * @param list<array{date: string, discipline: string, seconds: int, sessions: int}> $rows
     *
     * @return array{seconds: int, sessions: int, activeDays: int, averageSeconds: float}
     */
    private static function totals(array $rows): array
    {
        $seconds = array_sum(array_column($rows, 'seconds'));
        $sessions = array_sum(array_column($rows, 'sessions'));

        return [
            'seconds' => $seconds,
            'sessions' => $sessions,
            'activeDays' => \count(array_unique(array_column($rows, 'date'))),
            'averageSeconds' => $sessions > 0 ? (float) ($seconds / $sessions) : 0.0,
        ];
    }
}
