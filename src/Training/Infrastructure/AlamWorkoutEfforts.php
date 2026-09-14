<?php

declare(strict_types=1);

namespace App\Training\Infrastructure;

use App\Shared\Application\AlamEffort;
use App\Shared\Application\AlamEfforts;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Alam\AlamCalendar;
use App\Shared\Domain\Alam\AlamRules;
use App\Training\Domain\WorkoutRules;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use DateTimeImmutable;

/** L'édition réapplique la même exclusion calendaire que l'import, depuis les bornes fournisseur. */
final readonly class AlamWorkoutEfforts implements AlamEfforts
{
    public function __construct(private WorkoutRepository $workouts)
    {
    }

    public function within(array $sourceIds, DateTimeImmutable $start, DateTimeImmutable $end, GameRulesets $rules): array
    {
        if ([] === $sourceIds) {
            return [];
        }
        /** @var array<string, mixed> $alam */
        $alam = $rules->snapshot()['alam'];
        $calendar = new AlamCalendar(new AlamRules($alam));
        $workoutRules = WorkoutRules::runtime($rules);
        $efforts = [];
        foreach ($this->workouts->findBy(['id' => $sourceIds], ['startedAt' => 'ASC', 'id' => 'ASC']) as $workout) {
            $from = max($start, $workout->startedAt());
            $until = min($end, $workout->endedAt());
            $seconds = $calendar->retainedSeconds($from, $until);
            if ($workoutRules->isTooShort($seconds)) {
                continue;
            }
            $fraction = $seconds / max(1, $workout->durationSeconds());
            $efforts[] = new AlamEffort($workout->discipline(), $from, $until, $workoutRules->retainedDuration($seconds), null === $workout->distanceMeters() ? null : (int) floor($workout->distanceMeters() * $fraction), null === $workout->elevationGainMeters() ? null : (int) floor($workout->elevationGainMeters() * $fraction));
        }

        return $efforts;
    }
}
