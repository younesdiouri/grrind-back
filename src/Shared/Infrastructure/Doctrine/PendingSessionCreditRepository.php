<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Notification\PendingSessionCredit;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Repository tombstone #255. Il est conservé avec son mapping afin que
 * {@see \App\Shared\Application\AnnounceSessionCreditHandler} puisse refermer les fenêtres
 * créées avant le déploiement ; aucun chemin nominal n'appelle plus `recordSession()`.
 *
 * @extends ServiceEntityRepository<PendingSessionCredit>
 */
class PendingSessionCreditRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        /**
         * Depuis combien de temps une fenêtre non refermée compte comme abandonnée plutôt
         * qu'en vol — même valeur que `PendingGuildActivityRepository`, `notifications.yaml`
         * (`stale_window_minutes`) n'a aucune raison de diverger entre les deux
         * destinataires d'une même séance créditée.
         */
        private readonly int $staleWindowMinutes,
    ) {
        parent::__construct($registry, PendingSessionCredit::class);
    }

    /**
     * Vestige de l'ancien producteur #252, gardé avec le repository pendant le rollout pour
     * ne pas contracter l'entité ou son API avant #256. Aucun chemin nominal ne l'appelle.
     *
     * `INSERT ... ON CONFLICT DO NOTHING`, même geste que
     * `PendingGuildActivityRepository::recordSession()` — voir son docblock pour pourquoi le
     * conflit d'insertion ne veut pas toujours dire « une notification est déjà en vol » et
     * pour ce qui rend une reprogrammation sûre malgré le #134.
     */
    public function recordSession(Uuid $playerId, Discipline $discipline, int $durationSeconds, int $xpGranted, int $levelBefore, int $levelAfter, DateTimeImmutable $now): ?Uuid
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($playerId, $discipline, $durationSeconds, $xpGranted, $levelBefore, $levelAfter, $now): ?Uuid {
            $windowId = Uuid::v7();

            $inserted = $this->getEntityManager()->getConnection()->executeStatement(
                <<<'SQL'
                    INSERT INTO shared_pending_session_credit
                        (player_id, window_id, opened_at, sessions_count, total_xp_granted, last_discipline, last_duration_seconds, initial_level, current_level)
                    VALUES (:playerId, :windowId, :openedAt, 1, :xpGranted, :discipline, :durationSeconds, :levelBefore, :levelAfter)
                    ON CONFLICT (player_id) DO NOTHING
                    SQL,
                [
                    'playerId' => $playerId->toRfc4122(),
                    'windowId' => $windowId->toRfc4122(),
                    'openedAt' => $now,
                    'xpGranted' => $xpGranted,
                    'discipline' => $discipline->value,
                    'durationSeconds' => $durationSeconds,
                    'levelBefore' => $levelBefore,
                    'levelAfter' => $levelAfter,
                ],
                ['openedAt' => Types::DATETIMETZ_IMMUTABLE],
            );

            if (1 === $inserted) {
                return $windowId;
            }

            $pending = $this->find($playerId, LockMode::PESSIMISTIC_WRITE);
            \assert($pending instanceof PendingSessionCredit);
            // Même remarque qu'à `PendingGuildActivityRepository::recordSession()` : l'`INSERT`
            // ci-dessus est passé sous le nez de l'unité de travail si cette ligne y était
            // déjà chargée.
            $this->getEntityManager()->refresh($pending);

            $ageInMinutes = ($now->getTimestamp() - $pending->openedAt()->getTimestamp()) / 60;
            $isAbandoned = $ageInMinutes >= $this->staleWindowMinutes;

            $pending->addSession($discipline, $durationSeconds, $xpGranted, $levelAfter);
            $this->getEntityManager()->flush();

            return $isAbandoned ? $pending->windowId() : null;
        });
    }

    /**
     * La fenêtre en attente de ce joueur, si c'est encore **cette fenêtre-là** — `null`
     * sinon, que le tombeau l'ait déjà refermée ({@see self::close()}).
     *
     * Volontairement sans verrou, même remarque que
     * `PendingGuildActivityRepository::activityFor()` : cette lecture construit le contenu
     * d'un envoi déjà décidé, pas un arbitrage d'écriture concurrent.
     */
    public function activityFor(Uuid $playerId, Uuid $windowId): ?PendingSessionCredit
    {
        $pending = $this->find($playerId);

        if (null === $pending || !$pending->windowId()->equals($windowId)) {
            return null;
        }

        return $pending;
    }

    /**
     * Referme idempotemment une fenêtre historique après son drainage par
     * {@see \App\Shared\Application\AnnounceSessionCreditHandler}.
     */
    public function close(Uuid $playerId, Uuid $windowId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM shared_pending_session_credit WHERE player_id = :playerId AND window_id = :windowId',
            ['playerId' => $playerId->toRfc4122(), 'windowId' => $windowId->toRfc4122()],
        );
    }
}
