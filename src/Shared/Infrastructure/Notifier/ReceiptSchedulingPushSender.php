<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Notifier;

use App\Shared\Application\PushNotification;
use App\Shared\Application\PushSender;
use App\Shared\Infrastructure\Doctrine\PendingPushReceiptRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Décore {@see ExpoPushSender} pour la seule chose que le #131 ajoute : se souvenir d'un
 * ticket accepté, et programmer l'interrogation de son reçu.
 *
 * **Une décoration, pas une dépendance de plus sur `ExpoPushSender`.** Le sender traduit et
 * envoie ; ni Doctrine ni Messenger n'ont de raison d'être sur son constructeur pour ça, et
 * `ExpoPushSenderTest` — délibérément « sans conteneur, sans réseau » selon son propre
 * docblock — n'aurait plus pu s'en passer sinon. Ce fichier porte les deux dépendances à sa
 * place, câblé comme seule implémentation du port {@see PushSender} dans `config/services.yaml`
 * — `ExpoPushSender` n'est plus consommé qu'à travers lui.
 *
 * **Un ticket sans `ticketId`** (le transport nul de dev/test, voir `notifier.yaml`) n'a
 * rien à interroger plus tard : rien n'est enregistré, rien n'est dispatché. C'était vrai
 * avant le #150, faux le temps que `ExpoPushSender` dépende de `TexterInterface` — qui
 * rendait un `ticketId` nul même avec le vrai bridge Expo, voir son docblock — et c'est
 * redevenu vrai depuis : seul le transport nul ne fabrique jamais d'identifiant, un envoi
 * réel en fabrique toujours un.
 *
 * **Un seul message différé par appel à `send()`, jamais un par ticket.** Voir le docblock
 * de {@see CheckExpoPushReceiptsHandler} pour comment il retrouve, lui, tout ce qui est mûr à
 * interroger — y compris les tickets d'autres appels à `send()`, ceux d'une rafale de guilde
 * compris — dans un seul appel Expo.
 */
final readonly class ReceiptSchedulingPushSender implements PushSender
{
    public function __construct(
        private PushSender $sender,
        private PendingPushReceiptRepository $pendingReceipts,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function send(Uuid $userId, PushNotification $notification): array
    {
        $tickets = $this->sender->send($userId, $notification);
        $now = $this->clock->now();
        $hasPendingReceipt = false;

        foreach ($tickets as $ticket) {
            if ($ticket->accepted && null !== $ticket->ticketId) {
                $this->pendingReceipts->record($ticket->ticketId, $ticket->pushToken, $now);
                $hasPendingReceipt = true;
            }
        }

        if ($hasPendingReceipt) {
            $this->bus->dispatch(new CheckExpoPushReceipts(), [new DelayStamp(CheckExpoPushReceipts::DELAY_MINUTES * 60_000)]);
        }

        return $tickets;
    }
}
