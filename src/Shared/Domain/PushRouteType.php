<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Le catalogue fermé des écrans qu'un tap sur un push peut ouvrir — la réponse à « où
 * mène-t-elle », posée par le #144 à côté de {@see NotificationCategory}, qui répond à une
 * question voisine mais différente : « quel type d'événement ». Un consommateur qui ne
 * lirait que `category` sait *quoi* s'est produit, jamais *lequel* — la ligue a beau être
 * fermée, elle ne dit pas de quel joueur il s'agit.
 *
 * Level-up et ligue apporteront leur propre cas le jour où un ticket en a besoin — ce n'est
 * pas un catalogue à deviner à l'avance.
 */
enum PushRouteType: string
{
    case PlayerProfile = 'PLAYER_PROFILE';

    /**
     * L'écran des Risālāt d'une guilde (#194). `targetId` porte **l'identifiant de la
     * guilde** et non celui de la Risāla : l'écran est propre à la guilde et ne prend pas
     * d'élément — `GET /api/guilds/mine/risalat` n'accepte aucun identifiant — et l'id d'une
     * Risāla n'est de toute façon pas une clé que le client sache résoudre.
     *
     * Il reste utile plutôt que décoratif : le jour où un compte appartient à plusieurs
     * guildes, la route sait déjà laquelle ouvrir, sans qu'un push en vol devienne ambigu.
     */
    case GuildRisalat = 'GUILD_RISALAT';
}
