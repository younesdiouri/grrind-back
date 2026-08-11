<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Doctrine;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\ProgressionSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ProgressionSnapshot>
 */
class ProgressionSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ClockInterface $clock)
    {
        parent::__construct($registry, ProgressionSnapshot::class);
    }

    public function ofPlayer(Uuid $userId): ?ProgressionSnapshot
    {
        return $this->find($userId);
    }

    /**
     * La ligne du joueur, **verrouillée en écriture** jusqu'à la fin de la transaction.
     *
     * C'est le point de sérialisation des complétions concurrentes d'un même compte : deux
     * requêtes qui arrivent ensemble lisent le même total, calculent le même niveau et
     * écrasent chacune le résultat de l'autre si rien ne les met en file. Le verrou porte
     * sur *une ligne* — deux joueurs différents ne s'attendent jamais.
     *
     * La ligne est créée si elle n'existe pas, hors ORM et en une seule requête : entre un
     * `SELECT` qui ne trouve rien et un `INSERT`, deux requêtes simultanées passent toutes
     * les deux, et la seconde ferme l'`EntityManager` sur la violation de clé primaire.
     * Même geste qu'à la réservation d'une clé d'idempotence, et pour la même raison.
     *
     * @throws TransactionRequiredException hors transaction : un verrou qui se relâche aussitôt ne verrouille rien
     */
    public function lockFor(Uuid $userId, LevelCurve $curve): ProgressionSnapshot
    {
        $this->insertIfMissing($userId, $curve);

        $snapshot = $this->find($userId, LockMode::PESSIMISTIC_WRITE);
        \assert($snapshot instanceof ProgressionSnapshot);

        // L'`INSERT` ci-dessus est passé sous le nez de l'unité de travail : si l'entité
        // avait déjà été chargée dans cette requête, elle porterait un état d'avant.
        $this->getEntityManager()->refresh($snapshot);

        return $snapshot;
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * `ON CONFLICT DO NOTHING` : le perdant d'une course ne lève pas, il constate que la
     * ligne existe. Les valeurs écrites sont celles d'un joueur qui n'a rien fait — c'est
     * la reprojection qui suit, dans la même transaction, qui leur donnera leur contenu.
     */
    private function insertIfMissing(Uuid $userId, LevelCurve $curve): void
    {
        $fresh = ProgressionSnapshot::untouched($userId, $curve, $this->clock->now());

        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO progression_snapshot (user_id, total_xp, level, xp_into_level, xp_to_next_level, earned_skill_points, updated_at)
                VALUES (:userId, :totalXp, :level, :xpIntoLevel, :xpToNextLevel, :earnedSkillPoints, :updatedAt)
                ON CONFLICT (user_id) DO NOTHING
                SQL,
            [
                'userId' => $userId->toRfc4122(),
                'totalXp' => $fresh->totalXp(),
                'level' => $fresh->level(),
                'xpIntoLevel' => $fresh->xpIntoLevel(),
                'xpToNextLevel' => $fresh->xpToNextLevel(),
                'earnedSkillPoints' => $fresh->earnedSkillPoints(),
                'updatedAt' => $fresh->updatedAt()->format('Y-m-d H:i:sP'),
            ],
            ['xpToNextLevel' => ParameterType::INTEGER],
        );
    }
}
