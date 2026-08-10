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

        // L'agrégat valide la transition et les seuils ; s'il refuse, rien n'est écrit
        // et le client apprend pourquoi dans le corps de l'erreur.
        $session->complete($this->clock->now(), $this->rules);

        // La séance et son événement dans un seul COMMIT : c'est l'outbox. Publier
        // après coup perdrait l'événement si le processus meurt entre les deux ;
        // publier avant annoncerait un fait encore annulable.
        $this->sessions->transactional(function () use ($session): void {
            $this->sessions->commit();
            $this->events->dispatch(self::completionOf($session));
        });

        return $session;
    }

    /**
     * L'événement se construit ici, dans le module qui détient le fait — mais la classe
     * vit dans `Shared`, sans quoi aucun autre module ne pourrait s'y abonner sans
     * importer `Training`. C'est toute la convention, voir {@see DomainEvent}.
     */
    private static function completionOf(TrainingSession $session): TrainingSessionCompleted
    {
        $endedAt = $session->endedAt();
        $durationSeconds = $session->durationSeconds();

        // Une séance qui vient d'être complétée les porte toutes les deux ; les lire
        // sans le dire laisserait un `null` filer dans le payload d'un abonné.
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
