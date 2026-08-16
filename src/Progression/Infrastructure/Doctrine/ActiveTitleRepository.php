<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Doctrine;

use App\Progression\Domain\ActiveTitle;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le titre affiché d'un joueur : un identifiant qui se lit et se remplace.
 *
 * Les deux écritures passent **sous** l'unité de travail — un `INSERT … ON CONFLICT` et un
 * `DELETE` en DQL. C'est pour ça que la lecture ne rend pas l'entité mais son seul
 * identifiant, par une requête scalaire : elle ne consulte pas la carte d'identité de
 * Doctrine, donc elle ne peut pas rendre l'état d'avant l'écriture qui vient d'avoir lieu
 * dans la même requête HTTP. L'entité `ActiveTitle` n'existe que pour porter le mapping,
 * donc le schéma et la migration.
 *
 * @extends ServiceEntityRepository<ActiveTitle>
 */
class ActiveTitleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActiveTitle::class);
    }

    /** L'identifiant du titre affiché, ou `null` si le joueur n'en porte aucun. */
    public function titleIdOf(Uuid $userId): ?string
    {
        $titleId = $this->createQueryBuilder('a')
            ->select('a.titleId')
            ->where('a.userId = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SCALAR_COLUMN)
        ;

        \assert(null === $titleId || \is_string($titleId));

        return $titleId;
    }

    /**
     * Les titres affichés de plusieurs joueurs, **en une requête**, indexés par UUID en
     * RFC 4122. Un joueur qui n'en porte aucun est absent de la table.
     *
     * @param list<Uuid> $userIds
     *
     * @return array<string, string>
     */
    public function titleIdsOf(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        /** @var list<array{userId: Uuid, titleId: string}> $rows */
        $rows = $this->createQueryBuilder('a')
            ->select('a.userId', 'a.titleId')
            ->where('a.userId IN (:ids)')
            ->setParameter('ids', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $userIds))
            ->getQuery()
            ->getResult();

        $titleIds = [];

        foreach ($rows as $row) {
            $titleIds[$row['userId']->toRfc4122()] = $row['titleId'];
        }

        return $titleIds;
    }

    /**
     * Pose le titre affiché, en remplaçant celui d'avant.
     *
     * `INSERT … ON CONFLICT DO UPDATE` plutôt qu'un `SELECT` suivi d'un `INSERT` ou d'un
     * `UPDATE` : un joueur qui tape deux fois sur le même bouton envoie deux requêtes, et
     * entre la lecture et l'écriture les deux passeraient — la seconde violerait la clé
     * primaire et fermerait l'`EntityManager`. Même geste qu'à la création de la ligne de
     * progression, et pour la même raison.
     *
     * La clé étrangère composée vers `player_title` refuse au passage un titre non
     * débloqué. Elle ne remplace pas le contrôle applicatif — elle le double, pour que
     * l'invariant tienne même si un appelant futur oublie de vérifier.
     */
    public function select(Uuid $userId, string $titleId, DateTimeImmutable $now): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO player_active_title (user_id, title_id, selected_at)
                VALUES (:userId, :titleId, :selectedAt)
                ON CONFLICT (user_id) DO UPDATE
                    SET title_id = EXCLUDED.title_id, selected_at = EXCLUDED.selected_at
                SQL,
            [
                'userId' => $userId->toRfc4122(),
                'titleId' => $titleId,
                'selectedAt' => $now->format('Y-m-d H:i:sP'),
            ],
        );
    }

    /** Ne plus rien afficher. L'absence de ligne, pas une ligne à vide. */
    public function clear(Uuid $userId): void
    {
        $this->createQueryBuilder('a')
            ->delete()
            ->where('a.userId = :userId')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->getQuery()
            ->execute()
        ;
    }
}
