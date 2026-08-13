<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * D'où vient un workout. Toute activité est une source attribuée : le modèle ne bouge pas
 * quand on branche un fournisseur de plus, c'est une case ici et un adapter.
 *
 * **Deux agrégateurs de plateforme, pas deux SDK.** Grrind ne parle jamais à Garmin, à
 * Samsung ni à Nike Run Club — il parle à ce dans quoi ils écrivent tous. C'est la bonne
 * granularité : une montre Garmin importée sur iPhone arrive en `APPLE_HEALTH`, la même sur
 * Android arrive en `HEALTH_CONNECT`, et aucune des deux ne demande une case de plus.
 *
 * La source est donc l'**os** qui a rapporté la donnée, pas l'appareil qui l'a mesurée. On
 * ne saurait pas distinguer le second de façon fiable, et on n'en a pas besoin : la
 * confiance qu'on accorde vient du fait que la donnée a été écrite par un fournisseur
 * système, pas d'une marque de bracelet.
 */
enum WorkoutSource: string
{
    case AppleHealth = 'APPLE_HEALTH';
    case HealthConnect = 'HEALTH_CONNECT';

    /**
     * Dérivé plutôt que passé en second paramètre : ça rend impossible la combinaison d'une
     * source déclarée et d'un crédit vérifié, dont personne n'a besoin et que tout le monde
     * finit par écrire.
     *
     * Les deux cases rendent la même valeur et le `match` a disparu, mais **la méthode
     * reste**. C'est ici que se décidera le crédit d'une source déclarée le jour où il en
     * revient une ; le mettre chez l'appelant, c'est le disperser sur chaque appelant.
     */
    public function defaultTrust(): TrustLevel
    {
        return TrustLevel::ProviderVerified;
    }
}
