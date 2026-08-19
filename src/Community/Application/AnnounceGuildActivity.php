<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\Uid\Uuid;

/**
 * « Referme l'annonce en attente de cet auteur, et envoie-la. » Un message interne à
 * `Community` (#133), jamais un événement de `Shared` : aucun autre module n'a besoin de
 * savoir qu'il existe, seul {@see AnnounceGuildActivityHandler} y répond.
 *
 * **Dispatché sur l'outbox comme un `DomainEvent`, sans en être un** — voir la ligne
 * dédiée de `config/packages/messenger.yaml`.
 *
 * **Toujours dispatché avec un `DelayStamp`** ({@see GuildActivityNotifier}) — et c'est un
 * délai d'*ordonnancement*, pas de calibrage d'une fenêtre d'agrégation. Ce message doit
 * toujours être traité après toutes les `WorkoutCredited` déjà en file pour le même lot,
 * sinon deux séances créditées la même seconde produisent deux annonces au lieu d'une.
 * Deux faits, vérifiés dans
 * `vendor/symfony/doctrine-messenger/Transport/Connection.php`, disent que cet ordre
 * n'est **pas** garanti par le transport seul : la requête qui sert les messages
 * disponibles (`Connection::get()`) trie uniquement par `available_at ASC`, sans
 * départage par `id` ; et `available_at` est un `Types::DATETIME_IMMUTABLE`, que DBAL
 * rend en `TIMESTAMP(0)` sur PostgreSQL — précision à la seconde, largement dans la
 * portée d'un import qui publie dix événements en quelques millisecondes. Sans délai,
 * l'ordre entre deux messages qui partagent la même seconde tiendrait à la façon dont
 * Postgres parcourt son index — vrai par accident, pas par construction — et casserait
 * dès qu'un second worker consomme la même file (#57) : l'un peut traiter l'annonce
 * pendant que l'autre écrit encore des séances du même lot.
 *
 * **Le mode dégradé reste possible, et c'est documenté plutôt que nié.** Si une séance
 * créditée arrive après que le délai s'est écoulé pour les précédentes, l'annonce est
 * déjà partie : la séance en retard rouvre une fenêtre
 * ({@see \App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository::recordSession()})
 * et une seconde annonce part. Dégradé, pas corrompu — aucune séance n'est perdue, aucun
 * destinataire n'est notifié à tort, seulement notifié deux fois au lieu d'une. Voir
 * `GuildActivityNotifierTest::testAnAnnouncementFlushedBetweenTwoSessionsProducesTwoAnnouncements()`.
 *
 * Voir `notifications.yaml` (`announcement_delay_seconds`) pour la valeur retenue et son
 * bénéfice accessoire — une seconde synchronisation qui arrive dans la minute rejoint la
 * même annonce au lieu d'en ouvrir une seconde.
 */
final readonly class AnnounceGuildActivity
{
    public function __construct(public Uuid $authorId)
    {
    }
}
