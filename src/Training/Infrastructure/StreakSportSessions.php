<?php

declare(strict_types=1);

namespace App\Training\Infrastructure;

use App\Shared\Application\SportSessions;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Training\Domain\WorkoutRules;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Le port {@see SportSessions} : les séances qui montent le palier du coffre (#346).
 *
 * « Créditée » se relit sur le workout lui-même : un workout est conservé sans crédit
 * quand il finit hors fenêtre **au moment de son import**, et `createdAt` est cet instant.
 */
final readonly class StreakSportSessions implements SportSessions
{
    public function __construct(
        private WorkoutRepository $workouts,
        private WorkoutRules $rules,
        private CreditingDisciplines $disciplines,
    ) {
    }

    public function startedBetween(Uuid $player, DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        $starts = [];
        foreach ($this->workouts->overlapping($player, $from, $until) as $workout) {
            if ($workout->startedAt() >= $from && $this->disciplines->credits($workout->discipline()) && $this->rules->isWithinWindow($workout->endedAt(), $workout->createdAt())) {
                $starts[] = $workout->startedAt();
            }
        }

        return $starts;
    }
}
