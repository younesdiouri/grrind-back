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
     * Le propriétaire est une **condition de la recherche**, pas un contrôle qui suit :
     * aucun appelant ne peut oublier de vérifier à qui est la séance.
     */
    public function ofPlayer(Uuid $userId, Uuid $sessionId): ?TrainingSession
    {
        return $this->findOneBy(['id' => $sessionId, 'userId' => $userId]);
    }

    /**
     * La séance en cours du joueur, ou `null`. L'unicité est garantie par
     * `uniq_training_session_active` ; l'ordre ne sert qu'à rester déterministe.
     */
    public function activeOf(Uuid $userId): ?TrainingSession
    {
        return $this->findOneBy(
            ['userId' => $userId, 'status' => SessionStatus::Active],
            ['id' => 'DESC'],
        );
    }

    /**
     * Lu **hors ORM**, et seulement pour le perdant d'une course : l'index partiel
     * rejette son INSERT, Doctrine ferme l'`EntityManager` sur l'échec du flush, plus
     * rien ne peut être chargé par lui. La connexion, elle, reste utilisable — et le
     * client a besoin de l'identifiant de la séance gagnante pour s'y rebrancher.
     */
    public function activeIdOf(Uuid $userId): ?Uuid
    {
        $id = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT id FROM training_session WHERE user_id = :userId AND status = :status',
            ['userId' => $userId->toRfc4122(), 'status' => SessionStatus::Active->value],
        );

        return \is_string($id) ? Uuid::fromString($id) : null;
    }

    /**
     * La dernière séance close **qui compte** pour le cooldown. Le filtre sur la durée
     * traduit la décision du ticket #8 : abandonnée sous le plancher, elle n'a pas eu
     * lieu. Il couvre aussi les complétions, au-dessus du plancher par construction.
     */
    public function lastCountedClosure(Uuid $userId, int $minimumDurationSeconds): ?TrainingSession
    {
        $previous = $this->createQueryBuilder('s')
            ->where('s.userId = :userId')
            ->andWhere('s.status != :active')
            ->andWhere('s.durationSeconds >= :minimum')
            ->setParameter('userId', $userId, UuidType::NAME)
            ->setParameter('active', SessionStatus::Active)
            ->setParameter('minimum', $minimumDurationSeconds)
            ->orderBy('s.endedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert(null === $previous || $previous instanceof TrainingSession);

        return $previous;
    }

    /**
     * L'ordre est celui de l'UUID v7, triable par construction : `id < :cursor` tient
     * lieu de pagination, sans colonne d'ordre ni `OFFSET`. Il coïncide avec `startedAt`
     * tant que tout vient du chronomètre ; le jour où une activité s'importera après
     * coup, il faudra un curseur composite `(startedAt, id)`.
     *
     * `$take` est volontairement plus grand que `$query->limit` : l'appelant lit une
     * ligne de plus pour savoir s'il existe une page suivante, et ne la rend pas.
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

    /**
     * Ce qui rend l'outbox atomique : l'`INSERT` de l'événement, écrit par le transport
     * Doctrine sur cette connexion, partage le `COMMIT` de la séance.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static fn (): mixed => $work());
    }
}
