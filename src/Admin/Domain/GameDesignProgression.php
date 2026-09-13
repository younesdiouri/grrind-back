<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Combat\Domain\CombatSnapshot;
use App\Progression\Domain\DailyLoad;
use App\Progression\Domain\DiminishingReturns;
use App\Progression\Domain\XpCalculator;
use App\Progression\Domain\XpRates;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\WorkoutSource;
use App\Shared\Domain\LocalDay;
use App\Shared\Domain\Timezone;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use App\Training\Application\ImportedWorkout;
use App\Training\Application\WorkoutOverlapArbitration;
use App\Training\Domain\WorkoutRules;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * L'horloge et le ledger sont des valeurs locales. Aucun handler, repository ou événement.
 * Les séances couvrent tout le programme comme un lot ; les jours sans énergie et ceux
 * précédant le départ comptent pour zéro dans le dénominateur fixe du jeu.
 *
 * @phpstan-import-type ProfileInput from GameDesignProfile
 * @phpstan-import-type ProgramInput from GameDesignProgram
 */
final class GameDesignProgression
{
    /** @param array<string, mixed> $published
     * @param array<string, mixed> $draft
     * @param ProfileInput         $profile
     * @param ProgramInput         $program
     *
     * @return array{published: array<string, mixed>, draft: array<string, mixed>}
     */
    public function compare(array $published, array $draft, array $profile, array $program): array
    {
        if ($program['weeks'] < 1 || $program['weeks'] > 52 || \count($program['sessions']) > 14 || 7 !== \count($program['energy'])) {
            throw new InvalidArgumentException('Un programme couvre 1 à 52 semaines, au plus 14 séances par semaine et 7 jours d’énergie.');
        }

        return ['published' => $this->evolve($published, $profile, $program), 'draft' => $this->evolve($draft, $profile, $program)];
    }

    /** @param array<string, mixed> $snapshot
     * @param ProfileInput $profile
     * @param ProgramInput $program
     *
     * @return array<string, mixed>
     */
    private function evolve(array $snapshot, array $profile, array $program): array
    {
        $rulesets = new FrozenGameRulesets($snapshot, GameRulesetVersion::of($snapshot));
        $timezone = Timezone::fromString($program['timezone']);
        $start = new DateTimeImmutable($program['startDate'].' 00:00:00', $timezone->toDateTimeZone());
        $workoutRules = WorkoutRules::runtime($rulesets);
        $rates = XpRates::runtime($rulesets);
        $calculator = new XpCalculator($rates, DiminishingReturns::runtime($rulesets), AttributeSplit::runtime($rulesets), $rulesets);
        $modifiers = GameDesignInputs::modifiers($profile['equipment'], $rulesets);
        /** @var list<array{discipline: string, active: bool}> $disciplines */
        $disciplines = $snapshot['disciplines'];
        $active = array_column($disciplines, 'active', 'discipline');
        $candidates = [];
        for ($day = 0; $day < $program['weeks'] * 7; ++$day) {
            $date = $start->modify('+'.$day.' days');
            foreach ($program['sessions'] as $index => $session) {
                if ((int) $date->format('N') !== $session['day']) {
                    continue;
                }
                $at = $date->setTime($session['hour'], $session['minute']);
                // Des secondes écoulées, même au changement d'heure : la durée se redérive des bornes.
                $end = $at->setTimestamp($at->getTimestamp() + $session['duration']);
                $candidates[] = new ImportedWorkout(\sprintf('%03d-%02d', $day, $index), WorkoutSource::AppleHealth, $session['discipline'], $at, $end, $session['distance'], null, $session['elevation']);
            }
        }
        usort($candidates, static fn (ImportedWorkout $a, ImportedWorkout $b): int => $a->startedAt <=> $b->startedAt);
        $eligible = [];
        $reasons = [];
        foreach ($candidates as $candidate) {
            if (!($active[$candidate->activityType] ?? false)) {
                $reasons[$candidate->externalId] = 'Sport désactivé ou absent';
            } elseif ($workoutRules->isTooShort($candidate->durationSeconds())) {
                $reasons[$candidate->externalId] = 'Durée sous le minimum';
            } else {
                $eligible[] = [$candidate, Discipline::from($candidate->activityType)];
            }
        }
        $skipped = [];
        $eligible = WorkoutOverlapArbitration::retain($eligible, $skipped);
        foreach ($skipped as $skip) {
            $reasons[$skip->externalId] = 'Chevauchement';
        }
        // Comme la passe d'écriture de l'import : les gagnants peuvent encore se recouvrir
        // après le remplacement d'un rival. Aucun enregistrement fictif ne doit les doubler.
        $busy = [];
        foreach ($eligible as [$candidate]) {
            foreach ($busy as $survivor) {
                if ($candidate->overlaps($survivor->startedAt, $survivor->endedAt)) {
                    $reasons[$candidate->externalId] = 'Chevauchement';
                    continue 2;
                }
            }
            $busy[] = $candidate;
        }
        $byDay = [];
        foreach ($candidates as $candidate) {
            $date = LocalDay::containing($candidate->startedAt, $timezone)->date;
            $byDay[$date][] = $candidate;
        }
        $state = $profile;
        $state['vitalityOverride'] = null;
        $initial = $state['strength'] + $state['endurance'] + $state['mobility'] + $state['dexterity'];
        $energy = [];
        $sessions = $weeks = [];
        $weekXp = 0;
        /** @var array{attributes: array{vitality: array{window_days: int}}} $snapshot */
        $windowDays = $snapshot['attributes']['vitality']['window_days'];
        for ($day = 0; $day < $program['weeks'] * 7; ++$day) {
            $date = $start->modify('+'.$day.' days');
            $energy[] = $program['energy'][(int) $date->format('N') - 1];
            $average = intdiv(array_sum(\array_slice($energy, -$windowDays)), $windowDays);
            $secondsToday = 0;
            $xpToday = [];
            foreach ($byDay[$date->format('Y-m-d')] ?? [] as $candidate) {
                $discipline = Discipline::from($candidate->activityType);
                $reason = $reasons[$candidate->externalId] ?? null;
                $xp = 0;
                $breakdown = [];
                $retained = 0;
                if (null === $reason && !$rates->credits($discipline)) {
                    $reason = 'Sans XP : contribue à la vitalité via l’énergie quotidienne';
                }
                if (null === $reason) {
                    $retained = $workoutRules->retainedDuration($candidate->durationSeconds());
                    $award = $calculator->calculate($discipline, $retained, $modifiers, new DailyLoad($secondsToday, $xpToday[$discipline->value] ?? 0), $candidate->distanceMeters, $candidate->elevationGainMeters);
                    $xp = $award->amount();
                    foreach ($award->attributeGains->toArray() as $attribute => $gain) {
                        $state[$attribute] += $gain;
                    }
                    foreach ($award->breakdown->lines as $line) {
                        $breakdown[] = ['source' => $line->source->value, 'amount' => $line->amount];
                    }
                    $secondsToday += $retained;
                    $xpToday[$discipline->value] = ($xpToday[$discipline->value] ?? 0) + $xp;
                }
                $weekXp += $xp;
                $sessions[] = ['id' => $candidate->externalId, 'at' => $candidate->startedAt->format(\DATE_ATOM), 'end' => $candidate->endedAt->format(\DATE_ATOM), 'discipline' => $discipline->value, 'duration' => $candidate->durationSeconds(), 'retained' => $retained, 'xp' => $xp, 'reason' => $reason, 'breakdown' => $breakdown, 'state' => $this->state($state, $rulesets, $average)];
            }
            if (6 === $day % 7) {
                $weeks[] = ['week' => intdiv($day, 7) + 1, 'date' => $date->format('Y-m-d'), 'xp' => $weekXp, 'state' => $this->state($state, $rulesets, $average)];
                $weekXp = 0;
            }
        }

        return ['version' => $rulesets->version(), 'initialXp' => $initial, 'sessions' => $sessions, 'weeks' => $weeks, 'streakBonus' => 0];
    }

    /** @param ProfileInput $profile
     * @return array<string, mixed>
     */
    private function state(array $profile, GameRulesets $rulesets, int $average): array
    {
        $progression = GameDesignInputs::progression($profile, $rulesets, $average, false);
        $fighter = GameDesignInputs::fighter($profile, $rulesets, $average, false);
        $profile['vitalityOverride'] = $progression->vitality;

        return ['totalXp' => $progression->attributes->total(), 'level' => $progression->level, 'xpIntoLevel' => $progression->xpIntoLevel, 'xpToNextLevel' => $progression->xpToNextLevel, 'attributes' => $progression->attributes->toArray(), 'vitality' => $progression->vitality, 'averageEnergy' => $average, 'vitalityBonusPermille' => $progression->vitalityBreakdown->bonusPermille, 'fighter' => CombatSnapshot::fighter($fighter['fighter']), 'profile' => $profile];
    }
}
