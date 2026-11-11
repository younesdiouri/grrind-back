<?php

declare(strict_types=1);

namespace App\Progression\UI\Http\Response;

use App\Progression\Application\WeekProgress;
use App\Progression\Application\WeekTrainingPlan;
use App\Progression\Application\WeekTrainingSession;
use App\Progression\Domain\WeekTotals;
use App\Progression\Domain\XpTransaction;
use DateTimeInterface;

/**
 * `GET /api/progression/week` (#348). `entries` réutilise `XpTransaction` tel quel, annulations
 * et suppléments compris : un seul type à décoder, et `current` reste la somme exacte de ce
 * qui est listé.
 */
final readonly class WeekProgressResource
{
    /**
     * @return array<string, mixed>
     */
    public static function from(WeekProgress $week, ?WeekTrainingPlan $plan = null): array
    {
        return [
            'startsAt' => $week->startsAt->format(DateTimeInterface::ATOM),
            'resetsAt' => $week->resetsAt->format(DateTimeInterface::ATOM),
            'current' => self::totals($week->current),
            'previous' => null === $week->previous ? null : self::totals($week->previous),
            'entries' => array_map(static fn (XpTransaction $t): array => XpTransactionResource::from($t)->toArray(), $week->entries),
            'trainingPlan' => null === $plan ? null : [
                'remaining' => $plan->remaining->toArray(),
                'remainingAfter' => $plan->remainingAfter->toArray(),
                'completesWeek' => 0 === $plan->remainingAfter->total(),
                'calculatedAt' => $plan->calculatedAt->format(DateTimeInterface::ATOM),
                'expiresAt' => $plan->expiresAt->format(DateTimeInterface::ATOM),
                'rulesetVersion' => $plan->rulesetVersion,
                'sessions' => array_map(static fn (WeekTrainingSession $s): array => [
                    'discipline' => $s->discipline->value,
                    'durationSeconds' => $s->durationSeconds,
                    'estimatedXp' => $s->award->amount(),
                    'estimatedGains' => $s->award->attributeGains->toArray(),
                ], $plan->sessions),
            ],
        ];
    }

    /**
     * @return array{xp: int, sessions: int, attributes: array{strength: int, endurance: int, mobility: int, dexterity: int}}
     */
    private static function totals(WeekTotals $totals): array
    {
        return ['xp' => $totals->xp, 'sessions' => $totals->sessions, 'attributes' => $totals->attributes->toArray()];
    }
}
