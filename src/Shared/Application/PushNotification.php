<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Le contenu d'une notification GRRIND, dans son propre vocabulaire — pas celui d'Expo.
 *
 * C'est ce qui justifie {@see PushSender} comme port plutôt que comme simple alias du
 * Notifier : `category` et `groupingKey` sont des concepts du domaine (quel type
 * d'événement, quelle notification une nouvelle vient remplacer), et c'est
 * l'implémentation — {@see \App\Shared\Infrastructure\Notifier\ExpoPushSender} — qui les
 * traduit vers le vocabulaire d'Expo : `category` devient `categoryId` **et**
 * `channelId` (les deux noms plateforme du même « quel type d'événement »), et
 * `groupingKey` rejoint `data` — Expo n'a pas d'équivalent serveur d'un remplacement par
 * clé, seul le client peut le faire, donc la clé doit lui parvenir. Un consommateur qui
 * construirait un `PushMessage` lui-même aurait cette traduction à refaire, ou pire, à
 * l'oublier.
 */
final readonly class PushNotification
{
    public function __construct(
        public string $title,
        public string $body,
        /**
         * Un identifiant opaque du type d'événement (ex. `guild-invite`,
         * `session-credited`) — aucun catalogue fermé n'existe encore, aucun ticket ne
         * l'a tranché. Il deviendra un enum backed le jour où un premier consommateur
         * fixera les valeurs closes, comme {@see \App\Identity\Domain\DevicePlatform} l'a
         * fait pour les plateformes.
         */
        public string $category,
        /**
         * La clé qui fait qu'une notification plus récente doit remplacer l'ancienne
         * plutôt que de s'empiler à côté. Ni Expo ni son API n'offrent de remplacement
         * par clé côté serveur — seul le champ `data` traverse jusqu'au client, donc
         * c'est lui qui applique la règle à réception.
         */
        public string $groupingKey,
    ) {
    }
}
