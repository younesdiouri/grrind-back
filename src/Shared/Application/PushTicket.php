<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Le sort d'un jeton pour un envoi : ce qu'Expo a répondu, sans rien en perdre.
 *
 * Expo répond par un ticket par jeton visé — accepté avec un identifiant à interroger
 * plus tard pour le reçu de livraison, ou refusé avec un {@see PushRejection} (le jeton
 * est mort, l'appareil a désinstallé l'app ou révoqué la permission —
 * `DeviceNotRegistered` étant le cas qui intéressera le plus le #131). Ce ticket n'est
 * utile à personne dans ce ticket-ci — {@see PushSender} n'a encore aucun consommateur —
 * mais il l'est déjà au suivant, qui invalidera les jetons morts à partir de ce que
 * `PushSender::send()` aura renvoyé. Un `send()` qui avalerait cette réponse pour rendre
 * `void` obligerait le #131 à la redemander à Expo, ou pire, à parser une exception pour
 * la retrouver.
 *
 * **`rejection` est le vocabulaire du domaine, `rawReason` celui d'Expo.** Un
 * consommateur qui décide (invalider un jeton, par exemple) lit `rejection` — un enum
 * fermé, jamais une sous-chaîne d'un message tiers. `rawReason` ne sert qu'à un humain
 * qui lit un log ; aucune règle ne doit se brancher dessus, voir le docblock de
 * {@see PushRejection} pour pourquoi il ne peut pas être plus fiable que ça.
 */
final readonly class PushTicket
{
    private function __construct(
        public string $pushToken,
        public bool $accepted,
        /** L'identifiant du ticket Expo, à échanger plus tard contre un reçu. `null` si refusé. */
        public ?string $ticketId,
        /** `null` si accepté. */
        public ?PushRejection $rejection,
        /** Le message brut du `TransportException` du bridge. `null` si accepté. */
        public ?string $rawReason,
    ) {
    }

    public static function accepted(string $pushToken, ?string $ticketId): self
    {
        return new self($pushToken, true, $ticketId, null, null);
    }

    public static function rejected(string $pushToken, PushRejection $rejection, string $rawReason): self
    {
        return new self($pushToken, false, null, $rejection, $rawReason);
    }
}
