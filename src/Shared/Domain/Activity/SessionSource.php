<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * D'où vient une séance. **Toute activité est une source attribuée** : c'est ce qui
 * fait que brancher Strava ou HealthKit n'ouvrira pas un second modèle à côté du
 * premier — juste une case de plus ici et un adapter.
 *
 * Une seule valeur est produite en v1. Les deux autres sont déclarées dès maintenant
 * parce qu'elles fixent la forme de la colonne et du contrat, pas parce qu'on les
 * attend demain.
 */
enum SessionSource: string
{
    case ManualTimer = 'MANUAL_TIMER';
    case Strava = 'STRAVA';
    case HealthKit = 'HEALTHKIT';

    /**
     * Le crédit qu'on accorde à une séance découle de sa provenance : un chronomètre
     * lancé par le joueur est une déclaration, une activité relue chez un fournisseur
     * tiers est vérifiée. Dériver plutôt que passer les deux en paramètre supprime la
     * combinaison absurde — `MANUAL_TIMER` + `PROVIDER_VERIFIED` — dont personne
     * n'aurait besoin et que tout le monde finirait par écrire.
     */
    public function defaultTrust(): TrustLevel
    {
        return match ($this) {
            self::ManualTimer => TrustLevel::Declared,
            self::Strava, self::HealthKit => TrustLevel::ProviderVerified,
        };
    }
}
