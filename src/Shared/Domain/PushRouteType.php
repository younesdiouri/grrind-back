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
 * **Une seule valeur pour l'instant**, comme {@see NotificationCategory} au #132 : le
 * #144 n'a qu'un consommateur, `GUILD_ACTIVITY` vers le profil de l'auteur. Level-up,
 * guilde et ligue apporteront leur propre cas le jour où un ticket en a besoin — ce n'est
 * pas un catalogue à deviner à l'avance.
 */
enum PushRouteType: string
{
    case PlayerProfile = 'PLAYER_PROFILE';
}
