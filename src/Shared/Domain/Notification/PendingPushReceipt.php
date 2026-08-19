<?php

declare(strict_types=1);

namespace App\Shared\Domain\Notification;

use App\Shared\Infrastructure\Doctrine\PendingPushReceiptRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Un ticket Expo accepté, en attente de son reçu de livraison (#131).
 *
 * **Pourquoi cette table existe.** Un reçu Expo ne porte que l'identifiant du ticket, jamais
 * le jeton visé — c'est nous qui devons nous souvenir de l'association, entre l'instant de
 * l'envoi ({@see \App\Shared\Infrastructure\Notifier\ExpoPushSender}) et celui, différé, où
 * {@see \App\Shared\Infrastructure\Notifier\CheckExpoPushReceiptsHandler} va interroger Expo.
 * Sans elle, un reçu `DeviceNotRegistered` arriverait sans qu'on sache quel appareil effacer.
 *
 * **Supprimée seulement quand un reçu la résout — jamais quand il manque.** Un reçu absent
 * n'est ni un reçu positif ni un reçu négatif : Expo peut ne pas encore l'avoir produit, ou
 * l'avoir déjà purgé après ses 24 heures de rétention (voir le docblock du handler). La ligne
 * reste alors en base, ni retentée indéfiniment ni effacée à tort — voir {@see PendingPushReceiptRepository::flush()}
 * et le docblock du handler pour la décision de retenter ou d'abandonner.
 *
 * **Grossit vite, ne vaut plus rien passé quelques jours, purge raccrochée au #43** — même
 * remarque que {@see NotificationDelivery} pour la même raison : les lignes normalement
 * résolues sont effacées au fil de l'eau par le handler, seules celles jamais résolues
 * (Expo n'a jamais répondu) attendent une tâche de rétention future.
 */
#[ORM\Entity(repositoryClass: PendingPushReceiptRepository::class)]
#[ORM\Table(name: 'shared_pending_push_receipt')]
#[ORM\UniqueConstraint(name: 'uniq_shared_pending_push_receipt_ticket', columns: ['ticket_id'])]
class PendingPushReceipt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $ticketId;

    #[ORM\Column(length: 255)]
    private string $pushToken;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    private function __construct(string $ticketId, string $pushToken, DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->ticketId = $ticketId;
        $this->pushToken = $pushToken;
        $this->createdAt = $now;
    }

    /**
     * @internal ne se crée que par {@see PendingPushReceiptRepository::record()}, qui écrit
     *           directement en DBAL — voir son docblock pour pourquoi. Cette instance ne sert
     *           qu'à typer les valeurs passées à l'`INSERT`, elle n'est jamais persistée par
     *           l'ORM.
     */
    public static function record(string $ticketId, string $pushToken, DateTimeImmutable $now): self
    {
        return new self($ticketId, $pushToken, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function ticketId(): string
    {
        return $this->ticketId;
    }

    public function pushToken(): string
    {
        return $this->pushToken;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
