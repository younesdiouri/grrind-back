<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\Exception\WorkoutNotActive;
use App\Training\Domain\Exception\WorkoutNotFound;
use App\Training\Domain\Workout;
use App\Training\Domain\WorkoutRules;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use Psr\Clock\ClockInterface;

/**
 * Ferme la séance sans rien accorder : la porte de sortie du joueur qui a lancé son
 * chronomètre par erreur.
 *
 * Jumeau de {@see CompleteSessionHandler} aujourd'hui, et pourtant distinct : au Lot 4 la
 * complétion devient une transaction quand l'abandon restera ces quatre lignes. Les
 * factoriser reviendrait à donner un `$outcome` à une méthode qui va cesser d'en avoir.
 */
final readonly class AbandonSessionHandler
{
    public function __construct(
        private WorkoutRepository $sessions,
        private ClockInterface $clock,
        private WorkoutRules $rules,
    ) {
    }

    /**
     * @throws WorkoutNotFound  la séance n'existe pas, ou n'est pas celle de ce joueur
     * @throws WorkoutNotActive la séance est déjà close
     */
    public function __invoke(AbandonSession $command): Workout
    {
        $session = $this->sessions->ofPlayer($command->userId, $command->sessionId)
            ?? throw new WorkoutNotFound($command->sessionId);

        $session->abandon($this->clock->now(), $this->rules);

        $this->sessions->commit();

        return $session;
    }
}
