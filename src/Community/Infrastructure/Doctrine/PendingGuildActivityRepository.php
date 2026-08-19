<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\PendingGuildActivity;
use App\Shared\Domain\Activity\Discipline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PendingGuildActivity>
 */
class PendingGuildActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingGuildActivity::class);
    }

    /**
     * Enregistre une séance créditée dans l'annonce en attente de son auteur, et rend
     * l'identifiant de **la fenêtre** si c'est la première séance à l'ouvrir — c'est cette
     * réponse qui décide, pour {@see \App\Community\Application\GuildActivityNotifier},
     * s'il faut programmer une annonce (avec ce `windowId`) ou laisser celle déjà en vol
     * s'en charger. `null` si la séance a rejoint une fenêtre déjà ouverte.
     *
     * `INSERT ... ON CONFLICT DO NOTHING`, même geste qu'{@see \App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository} :
     * le perdant d'une course entre deux séances créditées au même instant ne lève pas, il
     * constate que la ligne existe déjà et passe au verrou. Chaque opération se referme sur
     * son propre `flush` : contrairement à la transaction de complétion, aucun autre dépôt
     * n'a besoin d'y participer.
     */
    public function recordSession(Uuid $authorId, Discipline $discipline, int $durationSeconds, int $xpGranted): ?Uuid
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($authorId, $discipline, $durationSeconds, $xpGranted): ?Uuid {
            $windowId = Uuid::v7();

            $inserted = $this->getEntityManager()->getConnection()->executeStatement(
                <<<'SQL'
                    INSERT INTO community_pending_guild_activity
                        (author_id, window_id, sessions_count, total_xp_granted, last_discipline, last_duration_seconds)
                    VALUES (:authorId, :windowId, 1, :xpGranted, :discipline, :durationSeconds)
                    ON CONFLICT (author_id) DO NOTHING
                    SQL,
                [
                    'authorId' => $authorId->toRfc4122(),
                    'windowId' => $windowId->toRfc4122(),
                    'xpGranted' => $xpGranted,
                    'discipline' => $discipline->value,
                    'durationSeconds' => $durationSeconds,
                ],
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
            $pending->addSession($discipline, $durationSeconds, $xpGranted);
            $this->getEntityManager()->flush();

            return null;
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
}
