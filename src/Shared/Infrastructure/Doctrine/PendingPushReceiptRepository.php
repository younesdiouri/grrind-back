<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Notification\PendingPushReceipt;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Écrite en DBAL, jamais via l'ORM — même raison qu'{@see NotificationAttemptRepository} :
 * {@see \App\Shared\Infrastructure\Notifier\ExpoPushSender::send()} appelle {@see record()}
 * dans une boucle sur les appareils d'un joueur, et un `flush()` déclenché là fuiterait sur
 * l'`EntityManager` du consommateur plutôt que de rester une écriture isolée.
 *
 * @extends ServiceEntityRepository<PendingPushReceipt>
 */
class PendingPushReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingPushReceipt::class);
    }

    /**
     * `ON CONFLICT (ticket_id) DO NOTHING` : un identifiant de ticket Expo n'est censé
     * apparaître qu'une fois, mais un rejeu accidentel du même envoi ne doit pas faire tomber
     * l'appelant pour une ligne qui ne vaut de toute façon rien de plus que la première.
     */
    public function record(string $ticketId, string $pushToken, DateTimeImmutable $now): void
    {
        $receipt = PendingPushReceipt::record($ticketId, $pushToken, $now);

        $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO shared_pending_push_receipt (id, ticket_id, push_token, created_at)
                VALUES (:id, :ticketId, :pushToken, :now)
                ON CONFLICT (ticket_id) DO NOTHING
                SQL,
            [
                'id' => $receipt->id()->toRfc4122(),
                'ticketId' => $receipt->ticketId(),
                'pushToken' => $receipt->pushToken(),
                'now' => $receipt->createdAt(),
            ],
            ['now' => Types::DATETIMETZ_IMMUTABLE],
        );
    }

    /**
     * Les tickets assez vieux pour qu'Expo ait eu le temps de produire un reçu — voir le
     * docblock de {@see \App\Shared\Infrastructure\Notifier\CheckExpoPushReceipts} pour le
     * délai retenu. `$limit` borne à ce que l'API accepte en un seul appel
     * ({@see \App\Shared\Infrastructure\Notifier\CheckExpoPushReceiptsHandler}) ; une guilde
     * plafonnée à quelques dizaines de membres par le snapshot publié n'en
     * approchera jamais la borne, mais la requête reste bornée par construction plutôt que
     * par une hypothèse sur la taille d'une guilde.
     *
     * @return list<PendingPushReceipt>
     */
    public function dueForCheck(DateTimeImmutable $threshold, int $limit): array
    {
        /** @var list<PendingPushReceipt> $due */
        $due = $this->createQueryBuilder('r')
            ->where('r.createdAt <= :threshold')
            ->orderBy('r.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('threshold', $threshold, Types::DATETIMETZ_IMMUTABLE)
            ->getQuery()
            ->getResult();

        return $due;
    }

    /**
     * Ne supprime que ce que le handler a jugé résolu — un reçu obtenu, favorable ou non.
     * Une ligne dont Expo n'a encore rien dit ne passe jamais par ici, voir le docblock de
     * {@see PendingPushReceipt}.
     */
    public function remove(PendingPushReceipt $receipt): void
    {
        $this->getEntityManager()->remove($receipt);
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
