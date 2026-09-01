<?php

declare(strict_types=1);

namespace App\Shared\Domain\Notification;

use App\Shared\Domain\NotificationCategory;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Une réservation d'envoi pour cet événement, à ce destinataire, dans cette catégorie
 * (#134) — pas une preuve de livraison (#149, le nom mentait). La ligne existe pour
 * rendre un handler de notification idempotent là où l'outbox ne l'est pas : le
 * transport livre *au moins une fois*, donc c'est cette réservation, et non un espoir
 * que le handler ne rejoue jamais, qui empêche un retry de renotifier quelqu'un déjà
 * servi. Elle ne dit rien de ce qui s'est passé après — ni si l'appel réseau est parti,
 * ni s'il a réussi. Pas de colonne d'issue (`SENT`/`NO_TARGET`) à côté : elle rendrait
 * la ligne mutable et demanderait une seconde écriture après l'appel réseau, celle qui
 * manquera le jour où le worker meurt entre les deux. Ce que « livré » veut dire se
 * regarde dans les logs du sender, pas ici.
 *
 * **L'unicité porte sur (event, recipient, category), pas sur `event` seul** — même
 * geste que {@see \App\Shared\Domain\Idempotency\IdempotencyRecord} sur (user, key) :
 * un même événement notifie plusieurs destinataires, et le même destinataire peut un
 * jour recevoir deux catégories différentes pour un seul événement (ex. une notification
 * de guilde et une invitation) sans que l'une doive bloquer l'autre.
 *
 * **`event` n'est jamais l'identifiant d'un `DomainEvent` de `Shared`, ni figé à un
 * type.** C'est au consommateur de décider ce qui identifie *une* occurrence à notifier
 * — voir {@see \App\Community\Application\AnnounceGuildActivity} pour l'exemple qui a
 * motivé cette table : l'auteur seul confondrait deux fenêtres d'agrégation successives
 * du même joueur, et rendrait la seconde muette pour toujours.
 *
 * **Réservée avant l'appel réseau, jamais après — cet ordre ne bouge pas (#149).** Une
 * collision au `claim()` de {@see NotificationAttemptRepository} veut dire « déjà
 * réservé », pas une erreur — le consommateur passe au destinataire suivant sans y
 * toucher. Réserver après l'envoi laisserait exactement la fenêtre que cette table
 * existe pour fermer : un handler qui envoie puis échoue avant l'accusé de réception
 * rejouerait l'envoi. C'est cet ordre qui rend `AnnounceGuildActivityHandler` idempotent
 * sous une outbox at-least-once ; l'inverser rouvrirait le double envoi que le #134 a
 * fermé.
 *
 * **Purgeable, et raccrochée au #43** : cette table grossit d'une ligne par tentative
 * et n'a plus de valeur passé quelques jours — `createdAt` est ce sur quoi une tâche de
 * rétention future filtrera. Elle sert aussi de matière au #41 (combien de notifications,
 * combien rejetées) tant qu'elle vit.
 */
#[ORM\Entity(repositoryClass: NotificationAttemptRepository::class)]
#[ORM\Table(name: 'shared_notification_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_shared_notification_attempt', columns: ['event_id', 'recipient_id', 'category'])]
class NotificationAttempt
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $eventId;

    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $recipientId;

    /**
     * Trace d'audit, pas le catalogue vivant : une ancienne valeur reste lisible même après
     * sa sortie de {@see NotificationCategory}. Le #256 conserve ainsi l'historique sans
     * migration de données et sans laisser cette valeur réapparaître dans les API de création
     * ou de préférences, qui restent typées par l'enum.
     */
    #[ORM\Column(length: 32)]
    private string $category;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    private function __construct(Uuid $eventId, Uuid $recipientId, NotificationCategory $category, DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->eventId = $eventId;
        $this->recipientId = $recipientId;
        $this->category = $category->value;
        $this->createdAt = $now;
    }

    /**
     * @internal ne se crée que par {@see NotificationAttemptRepository::claim()}, seul
     *           point qui sait distinguer une trace neuve d'une collision d'unicité
     */
    public static function record(Uuid $eventId, Uuid $recipientId, NotificationCategory $category, DateTimeImmutable $now): self
    {
        return new self($eventId, $recipientId, $category, $now);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function eventId(): Uuid
    {
        return $this->eventId;
    }

    public function recipientId(): Uuid
    {
        return $this->recipientId;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
