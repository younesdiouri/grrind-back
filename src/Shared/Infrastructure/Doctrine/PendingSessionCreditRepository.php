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
 * Le pendant, pour l'auteur lui-même, de
 * {@see \App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository} (#252) : même
 * geste d'agrégation par fenêtre, même garde-fou contre une fenêtre abandonnée — voir son
 * docblock pour le détail de chaque décision, reproduites ici à l'identique parce que la
 * raison ne change pas d'un mot en passant du destinataire « guilde » au destinataire
 * « auteur ».
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
     * Enregistre une séance créditée dans la fenêtre en attente de son auteur, et rend
     * l'identifiant de **la fenêtre** quand une notification doit être programmée pour elle
     * — c'est cette réponse qui décide, pour
     * {@see \App\Shared\Application\SessionCreditedNotifier}, s'il faut dispatcher
     * `AnnounceSessionCredit` (avec ce `windowId`) ou laisser une notification déjà en vol
     * s'en charger. `null` si la séance a rejoint une fenêtre déjà ouverte et encore
     * fraîche.
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
     * sinon, que la fenêtre ait déjà été refermée ({@see self::close()}) par une exécution
     * précédente du même message, ou qu'une seconde fenêtre l'ait remplacée entre-temps
     * (mode dégradé, voir {@see \App\Shared\Application\AnnounceSessionCredit}).
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
     * Referme la fenêtre — appelée une fois que
     * {@see \App\Shared\Application\AnnounceSessionCreditHandler} a fini d'essayer l'envoi,
     * jamais avant : même geste que `PendingGuildActivityRepository::close()`.
     */
    public function close(Uuid $playerId, Uuid $windowId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM shared_pending_session_credit WHERE player_id = :playerId AND window_id = :windowId',
            ['playerId' => $playerId->toRfc4122(), 'windowId' => $windowId->toRfc4122()],
        );
    }
}
