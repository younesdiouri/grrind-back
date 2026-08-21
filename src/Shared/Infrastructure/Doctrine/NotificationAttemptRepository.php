<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Notification\NotificationAttempt;
use App\Shared\Domain\NotificationCategory;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Écrite en DBAL, jamais via l'ORM — même raison qu'{@see IdempotencyRecordRepository} :
 * la réservation doit être atomique (`INSERT … ON CONFLICT`, ce que l'ORM ne sait pas
 * exprimer) et un échec ne doit pas emporter l'`EntityManager` du consommateur, qui a
 * souvent une boucle à continuer après une collision.
 *
 * @extends ServiceEntityRepository<NotificationAttempt>
 */
class NotificationAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotificationAttempt::class);
    }

    /**
     * Réserve l'envoi de cet événement à ce destinataire, dans cette catégorie —
     * `true` si c'est la première fois (au consommateur d'envoyer), `false` si une trace
     * existe déjà (au consommateur de passer au suivant sans y toucher, voir le docblock
     * de {@see NotificationAttempt}).
     */
    public function claim(Uuid $eventId, Uuid $recipientId, NotificationCategory $category, DateTimeImmutable $now): bool
    {
        $attempt = NotificationAttempt::record($eventId, $recipientId, $category, $now);

        $inserted = $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO shared_notification_attempt (id, event_id, recipient_id, category, created_at)
                VALUES (:id, :eventId, :recipientId, :category, :now)
                ON CONFLICT (event_id, recipient_id, category) DO NOTHING
                SQL,
            [
                'id' => $attempt->id()->toRfc4122(),
                'eventId' => $attempt->eventId()->toRfc4122(),
                'recipientId' => $attempt->recipientId()->toRfc4122(),
                'category' => $attempt->category()->value,
                'now' => $attempt->createdAt(),
            ],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );

        return 1 === $inserted;
    }
}
