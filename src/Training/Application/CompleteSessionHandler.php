<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\Exception\SessionNotActive;
use App\Training\Domain\Exception\SessionNotFound;
use App\Training\Domain\TrainingSession;
use App\Training\Infrastructure\Doctrine\TrainingSessionRepository;
use Psr\Clock\ClockInterface;

/**
 * Clôt la séance et fige sa durée. C'est le moment qui compte du produit, et pour
 * l'instant il ne fait que ça : au Lot 4, ce handler deviendra la transaction de
 * complétion — verrou sur la ligne de progression, résolution des modificateurs, calcul
 * d'XP, loot, streak, outbox — et rendra un `RewardSummary`.
 *
 * Ce qui ne bougera plus, en revanche, c'est ce qui est écrit ici : l'horloge est
 * serveur, la séance appartient à son auteur, et l'écriture est rejouable. Le reste
 * s'ajoutera autour.
 */
final readonly class CompleteSessionHandler
{
    public function __construct(
        private TrainingSessionRepository $sessions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws SessionNotFound  la séance n'existe pas, ou n'est pas celle de ce joueur
     * @throws SessionNotActive la séance est déjà close
     */
    public function __invoke(CompleteSession $command): TrainingSession
    {
        $session = $this->sessions->ofPlayer($command->userId, $command->sessionId)
            ?? throw new SessionNotFound($command->sessionId);

        // L'agrégat valide la transition ; s'il refuse, rien n'est écrit et le client
        // apprend le statut réel dans le corps de l'erreur.
        $session->complete($this->clock->now());

        $this->sessions->commit();

        return $session;
    }
}
