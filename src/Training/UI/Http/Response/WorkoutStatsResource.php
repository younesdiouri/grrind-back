<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Domain\WorkoutStats;

final readonly class WorkoutStatsResource
{
    /** @return array<string, mixed> */
    public static function from(WorkoutStats $stats): array
    {
        return [
            'period' => $stats->period->value,
            'timezone' => $stats->timezone->identifier,
            'start' => $stats->window->start->format('c'),
            'end' => $stats->window->end->format('c'),
            'observedEnd' => $stats->window->observedEnd->format('c'),
            'previousStart' => $stats->window->previousStart->format('c'),
            'previousEnd' => $stats->window->previousEnd->format('c'),
            'days' => $stats->window->days,
            'current' => $stats->current,
            'previous' => $stats->previous,
            'change' => $stats->change,
            'grade' => $stats->grade?->value,
            'daily' => array_map(static fn (array $day): array => [
                ...$day, 'date' => $day['date']->format('c'),
            ], $stats->daily),
            'buckets' => array_map(static fn (array $bucket): array => [
                ...$bucket, 'from' => $bucket['from']->format('c'), 'to' => $bucket['to']->format('c'),
            ], $stats->buckets),
            'disciplines' => $stats->disciplines,
        ];
    }
}
