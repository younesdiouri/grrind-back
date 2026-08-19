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
 * dédiée de `config/packages/messenger.yaml`. C'est ce détour asynchrone, et non un délai
 * artificiel à calibrer, qui fait l'agrégation du ticket : {@see GuildActivityNotifier} ne
 * le dispatch que pour la **première** séance fraîche d'une nouvelle fenêtre, donc ce
 * message est toujours écrit *après* toutes les séances créditées déjà en file pour le
 * même lot d'import — elles y étaient avant que ce message n'existe. L'ordre du transport
 * (FIFO sur `available_at`) le fait donc toujours traiter après elles, qu'il s'écoule dix
 * millisecondes ou dix minutes entre les deux : un lot de dix séances ne programme qu'une
 * annonce, les neuf suivantes trouvent la ligne déjà ouverte et s'y ajoutent — voir
 * {@see \App\Community\Infrastructure\Doctrine\PendingGuildActivityRepository::recordSession()}.
 */
final readonly class AnnounceGuildActivity
{
    public function __construct(public Uuid $authorId)
    {
    }
}
