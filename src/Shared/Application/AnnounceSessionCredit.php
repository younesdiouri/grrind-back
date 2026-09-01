<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/**
 * « Referme la notification en attente de ce joueur, et envoie-la. » (#252) Le pendant, pour
 * l'auteur lui-même, de `App\Community\Application\AnnounceGuildActivity` : même contrat,
 * même raison d'exister, jamais un `DomainEvent` — seul
 * {@see AnnounceSessionCreditHandler} y répond.
 *
 * **Dispatché sur l'outbox comme un `DomainEvent`, sans en être un** — voir la ligne dédiée
 * de `config/packages/messenger.yaml`.
 *
 * **Toujours dispatché avec un `DelayStamp`** ({@see SessionCreditedNotifier}), pour la
 * même raison qu'`AnnounceGuildActivity` : cette annonce doit toujours être traitée après
 * toutes les `WorkoutCredited` déjà en file pour le même lot, sinon deux séances créditées
 * la même seconde produisent deux pushes au lieu d'un. Voir le docblock
 * d'`AnnounceGuildActivity` pour la preuve détaillée (précision de `TIMESTAMP(0)` sur
 * `available_at`, absence de départage par `id`) — elle vaut mot pour mot ici, la file est
 * la même.
 *
 * **Le mode dégradé reste possible, et c'est documenté plutôt que nié** — même remarque
 * qu'`AnnounceGuildActivity` : une séance en retard sur le délai rouvre une fenêtre et un
 * second push part. Dégradé, pas corrompu.
 *
 * **`windowId`, pas seulement `playerId`**, même raison qu'`AnnounceGuildActivity` (#134) :
 * un rejeu doit retomber sur la même fenêtre, jamais une autre, et le mode dégradé en ouvre
 * légitimement une seconde pour le même joueur.
 */
final readonly class AnnounceSessionCredit
{
    public function __construct(public Uuid $playerId, public Uuid $windowId)
    {
    }
}
