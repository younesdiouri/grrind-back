<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Doctrine;

use App\Training\Domain\TrainingSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
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

    public function commit(): void
    {
        $this->getEntityManager()->flush();
    }
}
