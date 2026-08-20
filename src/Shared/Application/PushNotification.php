<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\NotificationCategory;

/**
 * Le contenu d'une notification GRRIND, dans son propre vocabulaire — pas celui d'Expo.
 *
 * C'est ce qui justifie {@see PushSender} comme port plutôt que comme simple alias du
 * Notifier : `category`, `groupingKey` et `route` sont des concepts du domaine (quel type
 * d'événement, quelle notification une nouvelle vient remplacer, où le tap doit mener), et
 * c'est l'implémentation — {@see \App\Shared\Infrastructure\Notifier\ExpoPushSender} — qui
 * les traduit vers le vocabulaire d'Expo : `category` devient `categoryId` **et**
 * `channelId` (les deux noms plateforme du même « quel type d'événement »), et
 * `groupingKey`/`route` rejoignent `data` — Expo n'a pas d'équivalent serveur ni d'un
 * remplacement par clé, ni d'un routage côté client, seul le client peut faire l'un et
 * l'autre, donc les deux doivent lui parvenir. Un consommateur qui construirait un
 * `PushMessage` lui-même aurait cette traduction à refaire, ou pire, à l'oublier.
 */
final readonly class PushNotification
{
    public function __construct(
        public string $title,
        public string $body,
        /**
         * Resté une chaîne libre depuis le #130, faute d'un premier consommateur pour
         * fixer le catalogue. Le #132 le tranche : {@see NotificationCategory} sert
         * aussi bien de clé aux préférences de `/api/me` que de type d'événement ici —
         * c'est la même notion, un joueur qui coupe `GUILD_ACTIVITY` coupe précisément
         * les envois qui portent cette catégorie.
         */
        public NotificationCategory $category,
        /**
         * La clé qui fait qu'une notification plus récente doit remplacer l'ancienne
         * plutôt que de s'empiler à côté. Ni Expo ni son API n'offrent de remplacement
         * par clé côté serveur — seul le champ `data` traverse jusqu'au client, donc
         * c'est lui qui applique la règle à réception.
         */
        public string $groupingKey,
        /**
         * « Où mène-t-elle » (#144) — une question différente de `groupingKey`, qui dit
         * seulement ce qu'une notification remplace. Sans elle, le seul routage possible
         * pour le client serait de découper `groupingKey`, un détail d'affichage que ce
         * dépôt a le droit de changer sans prévenir. Voir {@see PushRoute}.
         */
        public PushRoute $route,
    ) {
    }
}
