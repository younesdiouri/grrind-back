<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * D'où vient une séance. Toute activité est une source attribuée : brancher Strava ou
 * HealthKit n'ouvrira pas un second modèle, juste une case de plus ici et un adapter.
 *
 * Seule `ManualTimer` est produite en v1 ; les deux autres sont déclarées pour fixer la
 * forme de la colonne et du contrat.
 */
enum SessionSource: string
{
    case ManualTimer = 'MANUAL_TIMER';
    case Strava = 'STRAVA';
    case HealthKit = 'HEALTHKIT';

    /**
     * Dérivé plutôt que passé en second paramètre : ça rend impossible la combinaison
     * `MANUAL_TIMER` + `PROVIDER_VERIFIED`, dont personne n'a besoin et que tout le
     * monde finit par écrire.
     */
    public function defaultTrust(): TrustLevel
    {
        return match ($this) {
            self::ManualTimer => TrustLevel::Declared,
            self::Strava, self::HealthKit => TrustLevel::ProviderVerified,
        };
    }
}
