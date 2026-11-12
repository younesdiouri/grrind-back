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
 * Garde le meilleur candidat par ensemble de sports, puis au plus trois choix classés :
 * chacun apporte une discipline absente des choix précédents, sans séance de remplissage.
 * Une seule génération de candidats et une lecture de charge par sport, quel que soit
 * le nombre de choix. Le snapshot publié reste celui de l'opération courante.
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
        return $this->plansForWeek($userId, $week)[0] ?? null;
    }

    /** @return list<WeekTrainingPlan> Le principal en premier ; vide sans solution utile. */
    public function plansForWeek(Uuid $userId, WeekProgress $week): array
    {
        if (null === $week->previous || $week->current->sessions <= 0) {
            return [];
        }
        $remaining = self::deficit($week->previous->attributes, $week->current->attributes);
        if (0 === $remaining->total()) {
            return [];
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
        $bestBySports = [];
        $order = 0;
        foreach ($candidates as $index => $first) {
            $this->consider([$first], $remaining, $available, $bestBySports, $order++);
            foreach (\array_slice($candidates, $index + 1) as $second) {
                if ($first->discipline !== $second->discipline) {
                    $this->consider([$first, $second], $remaining, $available, $bestBySports, $order++);
                }
            }
        }

        usort($bestBySports, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);
        $plans = [];
        $seen = [];
        $version = $this->rules->version();
        foreach ($bestBySports as $candidate) {
            if ([] === array_diff($candidate['sports'], $seen)) {
                continue;
            }
            $plans[] = new WeekTrainingPlan($remaining, $candidate['after'], $candidate['sessions'], $now, $expires, $version);
            $seen = array_unique([...$seen, ...$candidate['sports']]);
            if (3 === \count($plans)) {
                break;
            }
        }

        return $plans;
    }

    /**
     * @param non-empty-list<WeekTrainingSession>                                                                                                                     $sessions
     * @param array<string, array{sessions: non-empty-list<WeekTrainingSession>, sports: list<string>, after: AttributeGains, rank: array{int, int|float, int, int}}> $bestBySports
     */
    private function consider(array $sessions, AttributeGains $remaining, int $available, array &$bestBySports, int $order): void
    {
        $seconds = array_sum(array_map(static fn (WeekTrainingSession $s): int => $s->durationSeconds, $sessions));
        if ($seconds > $available) {
            return;
        }
        $after = $remaining;
        foreach ($sessions as $session) {
            $after = self::deficit($after, $session->award->attributeGains);
        }
        // Ajouter un sport qui ne réduit aucun déficit de plus ne crée pas une alternative.
        if (2 === \count($sessions) && (self::deficit($remaining, $sessions[0]->award->attributeGains) == $after
            || self::deficit($remaining, $sessions[1]->award->attributeGains) == $after)) {
            return;
        }
        $coverage = 0.0;
        foreach ($remaining->toArray() as $attribute => $missing) {
            if ($missing > 0) {
                $coverage += ($missing - $after->toArray()[$attribute]) / $missing;
            }
        }
        $complete = 0 === $after->total();
        // L'ordre de parcours départage les égalités comme dans le plan unique d'origine.
        $rank = [$complete ? 0 : 1, $complete ? $seconds : -$coverage, $seconds, $order];
        $sports = array_map(static fn (WeekTrainingSession $s): string => $s->discipline->value, $sessions);
        sort($sports);
        $key = implode('+', $sports);
        if ($coverage > 0 && (!isset($bestBySports[$key]) || $rank < $bestBySports[$key]['rank'])) {
            $bestBySports[$key] = ['sessions' => $sessions, 'sports' => $sports, 'after' => $after, 'rank' => $rank];
        }
    }

    private static function deficit(AttributeGains $target, AttributeGains $gained): AttributeGains
    {
        return new AttributeGains(max(0, $target->strength - $gained->strength), max(0, $target->endurance - $gained->endurance), max(0, $target->mobility - $gained->mobility), max(0, $target->dexterity - $gained->dexterity));
    }
}
