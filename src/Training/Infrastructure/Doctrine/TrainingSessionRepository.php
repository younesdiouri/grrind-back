<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Doctrine;

use App\Training\Application\ListSessions;
use App\Training\Domain\SessionStatus;
use App\Training\Domain\TrainingSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<TrainingSession>
 */
class TrainingSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingSession::class);
    }

    public function add(TrainingSession $session): void
    {
        $this->getEntityManager()->persist($session);
    }

    /**
     * La séance de ce joueur, ou `null`. Le propriétaire est une **condition de la
     * recherche** et non un contrôle qui suivrait : on ne charge jamais la séance d'un
     * autre compte, donc aucun code d'appel ne peut oublier de vérifier à qui elle est.
     */
    public function ofPlayer(Uuid $userId, Uuid $sessionId): ?TrainingSession
    {
        return $this->findOneBy(['id' => $sessionId, 'userId' => $userId]);
    }

    /**
     * La séance en cours du joueur, ou `null`. L'unicité de la séance active est une
     * règle du jeu — garde-fou du ticket #10 — et non une garantie du schéma : tant
     * qu'elle n'est pas posée, on rend la plus récente plutôt que d'échouer sur un
     * doublon qui ne devrait pas exister.
     */
    public function activeOf(Uuid $userId): ?TrainingSession
    {
        return $this->findOneBy(
            ['userId' => $userId, 'status' => SessionStatus::Active],
            ['id' => 'DESC'],
        );
    }

    /**
     * L'historique du joueur, du plus récent au plus ancien, à partir du curseur.
     *
     * L'ordre est celui de l'UUID v7, triable par construction : c'est la raison du
     * choix de cette version, et ce qui permet à `id < :cursor` de tenir lieu de
     * pagination sans colonne d'ordre ni `OFFSET`. Il coïncide avec l'ordre de
     * `startedAt` tant que toutes les séances viennent du chronomètre ; le jour où une
     * activité s'importera après coup, il faudra un curseur composite `(startedAt, id)`.
     *
     * `$take` est distinct de `$query->limit` : l'appelant en demande une de plus pour
     * savoir s'il existe une page suivante, et c'est à lui de ne pas la rendre.
     *
     * @return list<TrainingSession>
     */
    public function history(ListSessions $query, int $take): array
    {
        $builder = $this->createQueryBuilder('s')
            ->where('s.userId = :userId')
            ->setParameter('userId', $query->userId, UuidType::NAME)
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($take);

        if (null !== $query->status) {
            $builder->andWhere('s.status = :status')->setParameter('status', $query->status);
        }

        if (null !== $query->discipline) {
            $builder->andWhere('s.discipline = :discipline')->setParameter('discipline', $query->discipline);
        }

        if (null !== $query->from) {
            $builder->andWhere('s.startedAt >= :from')->setParameter('from', $query->from);
        }

        if (null !== $query->to) {
            $builder->andWhere('s.startedAt <= :to')->setParameter('to', $query->to);
        }

        if (null !== $query->cursor) {
            $builder->andWhere('s.id < :cursor')->setParameter('cursor', $query->cursor, UuidType::NAME);
        }

        /** @var list<TrainingSession> $sessions */
        $sessions = $builder->getQuery()->getResult();

        return $sessions;
    }

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
