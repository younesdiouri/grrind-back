<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\Exception\SessionNotActive;
use App\Training\Domain\Exception\SessionNotFound;
use App\Training\Domain\TrainingRules;
use App\Training\Domain\TrainingSession;
use App\Training\Infrastructure\Doctrine\TrainingSessionRepository;
use Psr\Clock\ClockInterface;

/**
 * Ferme la séance sans rien accorder. C'est la porte de sortie du joueur qui a lancé son
 * chronomètre par erreur : sans elle, la règle « une seule séance active » le bloquerait
 * jusqu'au plafond de durée.
 *
 * Jumeau de {@see CompleteSessionHandler} aujourd'hui, et pourtant distinct : au Lot 4 la
 * complétion devient une transaction — verrou, XP, loot, streak, outbox — quand l'abandon
 * restera ces quatre lignes. Les factoriser maintenant reviendrait à donner un paramètre
 * `$outcome` à une méthode qui va cesser d'en avoir un.
 */
final readonly class AbandonSessionHandler
{
    public function __construct(
        private TrainingSessionRepository $sessions,
        private ClockInterface $clock,
        private TrainingRules $rules,
    ) {
    }

    /**
     * @throws SessionNotFound  la séance n'existe pas, ou n'est pas celle de ce joueur
     * @throws SessionNotActive la séance est déjà close
     */
    public function __invoke(AbandonSession $command): TrainingSession
    {
        $session = $this->sessions->ofPlayer($command->userId, $command->sessionId)
            ?? throw new SessionNotFound($command->sessionId);

        $session->abandon($this->clock->now(), $this->rules);

        $this->sessions->commit();

        return $session;
    }
}
