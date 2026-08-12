<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Application\SessionRewards;
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
 * La transaction de complétion : le moment dopamine, en **un seul** COMMIT.
 *
 * L'ordre n'est pas négociable, et c'est celui de CLAUDE.md :
 *
 * 1. **valider la transition d'état**, hors transaction. Une séance déjà close ou trop
 *    courte est refusée avant qu'aucun verrou ne soit pris — inutile de faire attendre les
 *    autres écritures du joueur pour rendre un 409 ;
 * 2. **ouvrir la transaction et écrire la séance**. Le verrou de ligne sur
 *    `training_session` est posé par cet `UPDATE` et court jusqu'au COMMIT ;
 * 3. **créditer**, par le port {@see SessionRewards}. C'est là que se pose le verrou
 *    pessimiste sur la ligne de progression, et que se déroule toute la séquence du Lot 3 :
 *    charge du jour, modificateurs, calcul, ledger, snapshot, titres ;
 * 4. **publier l'événement** dans l'outbox, en dernier : ce qui part au reste du système
 *    ne part que si tout ce qui précède a tenu.
 *
 * **Ce qui rend l'ensemble correct est en base, pas ici.** Deux complétions vraiment
 * simultanées de la même séance lisent toutes les deux une séance active — un contrôle
 * applicatif ne peut pas les départager. C'est
 * `uniq_xp_transaction_source_reason` qui refuse le second crédit et fait tout annuler ;
 * la perdante rend un 500, sa clé d'idempotence est relâchée, et le client qui retente
 * reçoit le 409 qui décrit la réalité. Une séance ne peut donc pas être créditée deux fois,
 * quelle que soit la course.
 *
 * Les points d'extension du loot (Lot 6) et du streak (Lot 5) prennent place entre 3 et 4,
 * chacun derrière son port : ils lisent l'état que le crédit vient d'écrire, et ils doivent
 * être défaits avec lui.
 */
final readonly class CompleteSessionHandler
{
    public function __construct(
        private TrainingSessionRepository $sessions,
        private ClockInterface $clock,
        private TrainingRules $rules,
        private SessionRewards $rewards,
        private MessageBusInterface $events,
    ) {
    }

    /**
     * @throws SessionNotFound  la séance n'existe pas, ou n'est pas celle de ce joueur
     * @throws SessionNotActive la séance est déjà close
     * @throws SessionTooShort  la séance n'a pas atteint la durée plancher
     */
    public function __invoke(CompleteSession $command): SessionCompletion
    {
        $session = $this->sessions->ofPlayer($command->userId, $command->sessionId)
            ?? throw new SessionNotFound($command->sessionId);

        // L'agrégat valide la transition et les seuils ; s'il refuse, rien n'est écrit et
        // aucune transaction n'a été ouverte.
        $session->complete($this->clock->now(), $this->rules);

        return $this->sessions->transactional(function () use ($session): SessionCompletion {
            $this->sessions->commit();

            $completion = self::completionOf($session);

            // Le crédit avant la publication, et les deux dans le même COMMIT : un
            // abonné ne peut pas être réveillé sur une récompense qui n'existe pas.
            $reward = $this->rewards->creditFor($completion);
            $this->events->dispatch($completion);

            return new SessionCompletion($session, $reward);
        });
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
