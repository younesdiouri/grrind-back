<?php

declare(strict_types=1);

namespace App\Training\Infrastructure;

use App\Shared\Application\SportSessions;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Training\Domain\Workout;
use App\Training\Domain\WorkoutRules;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Le port {@see SportSessions} : les séances qui comptent pour le streak (#286).
 *
 * « Créditée » se relit sur le workout lui-même : un workout est conservé sans crédit
 * quand il finit hors fenêtre **au moment de son import**, et `createdAt` est cet instant.
 *
 * ponytail: tout l'historique du joueur est relu à chaque séance — une série peut remonter
 * à des mois, et rien ne la borne. Persister l'ancre de la série si le profil le demande.
 */
final readonly class StreakSportSessions implements SportSessions
{
    public function __construct(
        private WorkoutRepository $workouts,
        private WorkoutRules $rules,
        private CreditingDisciplines $disciplines,
    ) {
    }

    public function of(Uuid $player): array
    {
        $sessions = [];
        /** @var list<Workout> $workouts */
        $workouts = $this->workouts->findBy(['userId' => $player], ['startedAt' => 'ASC']);
        foreach ($workouts as $workout) {
            if ($this->disciplines->credits($workout->discipline()) && $this->rules->isWithinWindow($workout->endedAt(), $workout->createdAt())) {
                $sessions[] = ['id' => $workout->id()->toRfc4122(), 'startedAt' => $workout->startedAt(), 'seconds' => $this->rules->retainedDuration($workout->durationSeconds())];
            }
        }

        return $sessions;
    }
}
