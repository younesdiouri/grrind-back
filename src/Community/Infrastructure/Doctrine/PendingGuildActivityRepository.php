<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\PendingGuildActivity;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PendingGuildActivity>
 */
class PendingGuildActivityRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        /**
         * Depuis combien de temps une fenêtre non refermée compte comme abandonnée plutôt
         * qu'en vol — voir le docblock de {@see PendingGuildActivity} (`openedAt`) pour
         * pourquoi ce garde-fou existe (#134), et celui de `notifications.yaml`
         * (`stale_window_minutes`) pour la valeur retenue.
         */
        private readonly GameRulesets $rulesets,
    ) {
        parent::__construct($registry, PendingGuildActivity::class);
    }

    /**
     * Enregistre une séance créditée dans l'annonce en attente de son auteur, et rend
     * l'identifiant de **la fenêtre** quand une annonce doit être programmée pour elle —
     * c'est cette réponse qui décide, pour
     * {@see \App\Community\Application\GuildActivityNotifier}, s'il faut dispatcher
     * `AnnounceGuildActivity` (avec ce `windowId`) ou laisser une annonce déjà en vol s'en
     * charger. `null` si la séance a rejoint une fenêtre déjà ouverte et encore fraîche.
     *
     * `INSERT ... ON CONFLICT DO NOTHING`, même geste qu'{@see \App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository} :
     * le perdant d'une course entre deux séances créditées au même instant ne lève pas, il
     * constate que la ligne existe déjà et passe au verrou. Chaque opération se referme sur
     * son propre `flush` : contrairement à la transaction de complétion, aucun autre dépôt
     * n'a besoin d'y participer.
     *
     * **Le conflit d'insertion ne veut pas toujours dire « une annonce est déjà en vol »
     * (#134).** Depuis que `close()` ne referme plus la fenêtre en entrant dans le handler
     * mais après avoir essayé tous les destinataires, un handler qui épuise ses trois
     * tentatives (`messenger.yaml`) laisse la ligne ouverte pour de bon : plus rien ne la
     * referme jamais. Sans ce contrôle, `recordSession()` conclurait à chaque séance
     * suivante « une annonce est déjà programmée » et la guilde de cet auteur deviendrait
     * muette pour toujours, en silence. Une fenêtre plus vieille que
     * `$staleWindowMinutes` est donc traitée comme abandonnée : la séance rejoint quand
     * même son agrégat (rien n'est perdu), mais son `windowId` est rendu pour qu'une
     * nouvelle annonce reparte. **C'est la trace du #134 qui rend ça sûr** :
     * {@see \App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository::claim()}
     * écarte tout destinataire déjà servi par une tentative précédente sur ce même
     * `windowId`, donc reprogrammer une fenêtre abandonnée ne peut pas le renotifier.
     */
    public function recordSession(Uuid $authorId, Discipline $discipline, int $durationSeconds, int $xpGranted, DateTimeImmutable $now): ?Uuid
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($authorId, $discipline, $durationSeconds, $xpGranted, $now): ?Uuid {
            $windowId = Uuid::v7();

            $inserted = $this->getEntityManager()->getConnection()->executeStatement(
                <<<'SQL'
                    INSERT INTO community_pending_guild_activity
                        (author_id, window_id, opened_at, sessions_count, total_xp_granted, last_discipline, last_duration_seconds)
                    VALUES (:authorId, :windowId, :openedAt, 1, :xpGranted, :discipline, :durationSeconds)
                    ON CONFLICT (author_id) DO NOTHING
                    SQL,
                [
                    'authorId' => $authorId->toRfc4122(),
                    'windowId' => $windowId->toRfc4122(),
                    'openedAt' => $now,
                    'xpGranted' => $xpGranted,
                    'discipline' => $discipline->value,
                    'durationSeconds' => $durationSeconds,
                ],
                ['openedAt' => Types::DATETIMETZ_IMMUTABLE],
            );

            if (1 === $inserted) {
                return $windowId;
            }

            $pending = $this->find($authorId, LockMode::PESSIMISTIC_WRITE);
            \assert($pending instanceof PendingGuildActivity);
            // Même remarque qu'à {@see \App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository::lockFor()} : l'`INSERT`
            // ci-dessus est passé sous le nez de l'unité de travail si cette ligne y était
            // déjà chargée.
            $this->getEntityManager()->refresh($pending);

            $ageInMinutes = ($now->getTimestamp() - $pending->openedAt()->getTimestamp()) / 60;
            $isAbandoned = $ageInMinutes >= $this->staleWindowMinutes();

            $pending->addSession($discipline, $durationSeconds, $xpGranted);
            $this->getEntityManager()->flush();

            return $isAbandoned ? $pending->windowId() : null;
        });
    }

    /**
     * L'annonce en attente de cet auteur, si c'est encore **cette fenêtre-là** — `null`
     * sinon, que la fenêtre ait déjà été refermée ({@see self::close()}) par une exécution
     * précédente du même message, ou qu'une seconde fenêtre l'ait remplacée entre-temps
     * (mode dégradé). Dans les deux cas, {@see \App\Community\Application\AnnounceGuildActivityHandler}
     * n'a rien à faire : ni renvoyer ce qui l'a déjà été, ni toucher aux données d'une
     * fenêtre qui n'est pas la sienne (#134).
     *
     * Volontairement sans verrou : contrairement à `recordSession()`, cette lecture sert à
     * construire le contenu d'une annonce déjà décidée, pas à trancher un conflit d'écriture
     * concurrent. Une séance qui s'ajoute pendant l'envoi reste le même risque déjà accepté
     * et documenté — « dégradé, pas corrompu » — que celui qui existait quand `close()`
     * verrouillait puis supprimait en un seul geste.
     */
    public function activityFor(Uuid $authorId, Uuid $windowId): ?PendingGuildActivity
    {
        $pending = $this->find($authorId);

        if (null === $pending || !$pending->windowId()->equals($windowId)) {
            return null;
        }

        return $pending;
    }

    /**
     * Referme la fenêtre — appelée une fois que
     * {@see \App\Community\Application\AnnounceGuildActivityHandler} a fini d'essayer tous
     * ses destinataires, jamais avant : voir le docblock de {@see PendingGuildActivity}
     * pour pourquoi la lecture du contenu (`activityFor()`) et la fermeture sont deux
     * gestes séparés depuis le #134.
     *
     * Filtrée sur `(authorId, windowId)`, comme `activityFor()` : un appel après qu'une
     * seconde fenêtre a déjà pris la place de celle-ci ne supprime rien de la nouvelle.
     * Une suppression conditionnelle directe suffit — pas de verrou-puis-suppression comme
     * l'ancien `close()` : un second appel pour la même fenêtre (rejeu après succès) ne
     * supprime simplement rien, sans qu'il y ait de course à trancher.
     */
    public function close(Uuid $authorId, Uuid $windowId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM community_pending_guild_activity WHERE author_id = :authorId AND window_id = :windowId',
            ['authorId' => $authorId->toRfc4122(), 'windowId' => $windowId->toRfc4122()],
        );
    }

    private function staleWindowMinutes(): int
    {
        $snapshot = $this->rulesets->snapshot();
        /** @var array{stale_window_minutes: int} $notifications */
        $notifications = $snapshot['notifications'];

        return $notifications['stale_window_minutes'];
    }
}
