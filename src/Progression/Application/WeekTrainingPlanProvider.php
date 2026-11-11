<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\XpCalculator;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\ModifierResolver;
use App\Shared\Application\PlayerTimezones;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\LocalDay;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Compare une ou deux disciplines distinctes, par pas de 5 min entre 10 et 60 min.
 * Le plan complet le plus court gagne ; sinon, la meilleure réduction relative des
 * déficits. Le vrai calculateur tranche les gains, avec bonus actifs et plafonds du jour.
 * Terrain, cardio et bonus ensemble inconnus ne sont jamais inventés.
 */
final readonly class WeekTrainingPlanProvider
{
    public function __construct(
        private XpCalculator $calculator,
        private CreditingDisciplines $disciplines,
        private ModifierResolver $modifiers,
        private XpTransactionRepository $ledger,
        private PlayerTimezones $timezones,
        private GameRulesets $rules,
        private ClockInterface $clock,
    ) {
    }

    public function forWeek(Uuid $userId, WeekProgress $week): ?WeekTrainingPlan
    {
        if (null === $week->previous || $week->current->sessions <= 0) {
            return null;
        }
        $remaining = self::deficit($week->previous->attributes, $week->current->attributes);
        if (0 === $remaining->total()) {
            return null;
        }
        $now = $this->clock->now();
        $day = LocalDay::containing($now, $this->timezones->of($userId));
        $expires = min($day->endsAt, $week->resetsAt);
        $available = $expires->getTimestamp() - $now->getTimestamp();
        /** @var array{minimum_duration_seconds: int, maximum_duration_seconds: int} $training */
        $training = $this->rules->snapshot()['training'];
        $minimum = max(600, (int) ceil($training['minimum_duration_seconds'] / 300) * 300);
        $maximum = min(3600, $training['maximum_duration_seconds'], $available);
        $modifiers = $this->modifiers->of($userId, $now);
        $candidates = [];
        foreach ($this->disciplines->all() as $discipline) {
            $load = $this->ledger->dailyLoadOf($userId, $discipline, $day);
            for ($seconds = $minimum; $seconds <= $maximum; $seconds += 300) {
                $award = $this->calculator->calculate($discipline, $seconds, $modifiers, $load);
                if ($award->amount() > 0) {
                    $candidates[] = new WeekTrainingSession($discipline, $seconds, $award);
                }
            }
        }
        $best = [];
        $bestRemaining = $remaining;
        $bestCoverage = 0.0;
        $bestSeconds = \PHP_INT_MAX;
        foreach ($candidates as $index => $first) {
            $this->consider([$first], $remaining, $available, $best, $bestRemaining, $bestCoverage, $bestSeconds);
            foreach (\array_slice($candidates, $index + 1) as $second) {
                if ($first->discipline !== $second->discipline) {
                    $this->consider([$first, $second], $remaining, $available, $best, $bestRemaining, $bestCoverage, $bestSeconds);
                }
            }
        }

        return [] === $best ? null : new WeekTrainingPlan($remaining, $bestRemaining, $best, $now, $expires, $this->rules->version());
    }

    /**
     * @param non-empty-list<WeekTrainingSession> $sessions
     * @param list<WeekTrainingSession>           $best
     *
     * @param-out list<WeekTrainingSession> $best
     */
    private function consider(array $sessions, AttributeGains $remaining, int $available, array &$best, AttributeGains &$bestRemaining, float &$bestCoverage, int &$bestSeconds): void
    {
        $seconds = array_sum(array_map(static fn (WeekTrainingSession $s): int => $s->durationSeconds, $sessions));
        if ($seconds > $available) {
            return;
        }
        $after = $remaining;
        foreach ($sessions as $session) {
            $after = self::deficit($after, $session->award->attributeGains);
        }
        $coverage = 0.0;
        foreach ($remaining->toArray() as $attribute => $missing) {
            if ($missing > 0) {
                $coverage += ($missing - $after->toArray()[$attribute]) / $missing;
            }
        }
        $complete = 0 === $after->total();
        $bestComplete = 0 === $bestRemaining->total();
        $better = ($complete && !$bestComplete)
            || ($complete === $bestComplete && ($complete
                ? $seconds < $bestSeconds
                : ($coverage > $bestCoverage || ($coverage === $bestCoverage && $seconds < $bestSeconds))));
        if ($coverage > 0 && $better) {
            $best = $sessions;
            $bestRemaining = $after;
            $bestCoverage = $coverage;
            $bestSeconds = $seconds;
        }
    }

    private static function deficit(AttributeGains $target, AttributeGains $gained): AttributeGains
    {
        return new AttributeGains(max(0, $target->strength - $gained->strength), max(0, $target->endurance - $gained->endurance), max(0, $target->mobility - $gained->mobility), max(0, $target->dexterity - $gained->dexterity));
    }
}
