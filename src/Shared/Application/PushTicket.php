<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Le sort d'un jeton pour un envoi : ce qu'Expo a répondu, sans rien en perdre.
 *
 * Expo répond par un ticket par jeton visé — accepté avec un identifiant à interroger
 * plus tard pour le reçu de livraison, ou refusé avec un motif (le jeton est mort,
 * l'appareil a désinstallé l'app ou révoqué la permission — `DeviceNotRegistered` étant
 * le cas qui intéressera le plus le #131). Ce ticket n'est utile à personne dans ce
 * ticket-ci — {@see PushSender} n'a encore aucun consommateur — mais il l'est déjà au
 * suivant, qui invalidera les jetons morts à partir de ce que `PushSender::send()` aura
 * renvoyé. Un `send()` qui avalerait cette réponse pour rendre `void` obligerait le #131
 * à la redemander à Expo, ou pire, à parser une exception pour la retrouver.
 */
final readonly class PushTicket
{
    private function __construct(
        public string $pushToken,
        public bool $accepted,
        /** L'identifiant du ticket Expo, à échanger plus tard contre un reçu. `null` si refusé. */
        public ?string $ticketId,
        /**
         * Le message du `TransportException` levé par le bridge — texte libre, pas un
         * code Expo structuré : `ExpoTransport` ne distingue pas plus finement à
         * l'exception, et fabriquer ici une catégorisation qu'il ne fait pas serait
         * deviner un format non documenté. `null` si accepté.
         */
        public ?string $reason,
    ) {
    }

    public static function accepted(string $pushToken, ?string $ticketId): self
    {
        return new self($pushToken, true, $ticketId, null);
    }

    public static function rejected(string $pushToken, ?string $reason): self
    {
        return new self($pushToken, false, null, $reason);
    }
}
