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
     * Enregistre une séance créditée dans l'annonce en attente de son auteur, et dit si
     * c'est **la première** de la fenêtre — c'est cette réponse qui décide, pour
     * {@see \App\Community\Application\GuildActivityNotifier}, s'il faut programmer une
     * annonce ou laisser celle déjà en vol s'en charger.
     *
     * `INSERT ... ON CONFLICT DO NOTHING`, même geste qu'{@see \App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository} :
     * le perdant d'une course entre deux séances créditées au même instant ne lève pas, il
     * constate que la ligne existe déjà et passe au verrou. Chaque opération se referme sur
     * son propre `flush` : contrairement à la transaction de complétion, aucun autre dépôt
     * n'a besoin d'y participer.
     */
    public function recordSession(Uuid $authorId, Discipline $discipline, int $durationSeconds, int $xpGranted): bool
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($authorId, $discipline, $durationSeconds, $xpGranted): bool {
            $inserted = $this->getEntityManager()->getConnection()->executeStatement(
                <<<'SQL'
                    INSERT INTO community_pending_guild_activity
                        (author_id, sessions_count, total_xp_granted, last_discipline, last_duration_seconds)
                    VALUES (:authorId, 1, :xpGranted, :discipline, :durationSeconds)
                    ON CONFLICT (author_id) DO NOTHING
                    SQL,
                [
                    'authorId' => $authorId->toRfc4122(),
                    'xpGranted' => $xpGranted,
                    'discipline' => $discipline->value,
                    'durationSeconds' => $durationSeconds,
                ],
            );

            if (1 === $inserted) {
                return true;
            }

            $pending = $this->find($authorId, LockMode::PESSIMISTIC_WRITE);
            \assert($pending instanceof PendingGuildActivity);
            // Même remarque qu'à {@see \App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository::lockFor()} : l'`INSERT`
            // ci-dessus est passé sous le nez de l'unité de travail si cette ligne y était
            // déjà chargée.
            $this->getEntityManager()->refresh($pending);
            $pending->addSession($discipline, $durationSeconds, $xpGranted);
            $this->getEntityManager()->flush();

            return false;
        });
    }

    /**
     * Referme l'annonce en attente d'un auteur et en rend l'état final — verrouillée puis
     * supprimée dans la même transaction, pour qu'une séance créditée juste après ne se
     * perde pas dans une ligne qu'un envoi concurrent est en train de lire.
     *
     * `null` si aucune annonce n'est en attente : {@see \App\Community\Application\AnnounceGuildActivityHandler}
     * n'a alors rien à envoyer.
     */
    public function close(Uuid $authorId): ?PendingGuildActivity
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($authorId): ?PendingGuildActivity {
            $pending = $this->find($authorId, LockMode::PESSIMISTIC_WRITE);

            if (null === $pending) {
                return null;
            }

            $this->getEntityManager()->remove($pending);
            $this->getEntityManager()->flush();

            return $pending;
        });
    }
}
