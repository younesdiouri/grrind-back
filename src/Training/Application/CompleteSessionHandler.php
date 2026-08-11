<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Event\DomainEvent;
use App\Shared\Domain\Event\TrainingSessionCompleted;
use App\Training\Domain\Exception\SessionNotActive;
use App\Training\Domain\Exception\SessionNotFound;
use App\Training\Domain\Exception\SessionTooShort;
use App\Training\Domain\TrainingRules;
use App\Training\Domain\TrainingSession;
use App\Training\Infrastructure\Doctrine\TrainingSessionRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Clôt la séance et fige sa durée. Au Lot 4, ce handler deviendra la transaction de
 * complétion — verrou, modificateurs, XP, loot, streak — et rendra un `RewardSummary`.
 * Ce qui est écrit ici ne bougera pas : le reste s'ajoutera autour.
 */
final readonly class CompleteSessionHandler
{
    public function __construct(
        private TrainingSessionRepository $sessions,
        private ClockInterface $clock,
        private TrainingRules $rules,
        private MessageBusInterface $events,
    ) {
    }

    /**
     * @throws SessionNotFound  la séance n'existe pas, ou n'est pas celle de ce joueur
     * @throws SessionNotActive la séance est déjà close
     * @throws SessionTooShort  la séance n'a pas atteint la durée plancher
     */
    public function __invoke(CompleteSession $command): TrainingSession
    {
        $session = $this->sessions->ofPlayer($command->userId, $command->sessionId)
            ?? throw new SessionNotFound($command->sessionId);

        // L'agrégat valide la transition et les seuils ; s'il refuse, rien n'est écrit.
        $session->complete($this->clock->now(), $this->rules);

        // La séance et son événement dans un seul COMMIT : c'est l'outbox.
        $this->sessions->transactional(function () use ($session): void {
            $this->sessions->commit();
            $this->events->dispatch(self::completionOf($session));
        });

        return $session;
    }

    /** Construit ici, dans le module qui détient le fait ; classe dans `Shared`, sans
     * quoi personne ne pourrait s'y abonner. Voir {@see DomainEvent}. */
    private static function completionOf(TrainingSession $session): TrainingSessionCompleted
    {
        $endedAt = $session->endedAt();
        $durationSeconds = $session->durationSeconds();

        // Une séance complétée les porte toutes les deux ; sans l'assertion, un `null`
        // filerait dans le payload d'un abonné.
        \assert(null !== $endedAt && null !== $durationSeconds);

        return new TrainingSessionCompleted(
            $session->id(),
            $session->userId(),
            $session->discipline(),
            $session->startedAt(),
            $endedAt,
            $durationSeconds,
            $session->source(),
            $session->trust(),
        );
    }
}
