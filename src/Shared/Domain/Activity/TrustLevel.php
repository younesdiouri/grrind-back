<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * Distinct de {@see WorkoutSource} : la source dit *qui* a produit la donnée, le crédit dit
 * ce que le moteur en fait. Les deux se confondent tant que toutes les sources sont
 * vérifiées, et divergeront dès qu'une séance déclarée sera rapprochée après coup d'une
 * activité du fournisseur.
 *
 * Le sens est croissant : une séance ne perd jamais son crédit.
 *
 * **`DECLARED` n'a plus de producteur et reste quand même.** Ce n'est pas une source de
 * plus qu'on garderait « au cas où » — c'est le plancher d'une échelle ordonnée. Le retirer
 * laisserait un enum à une seule valeur, qui ne distingue plus rien, et qu'il faudrait
 * recréer au premier import déclaré. Une échelle croissante a besoin d'un bas.
 */
enum TrustLevel: string
{
    case Declared = 'DECLARED';
    case ProviderVerified = 'PROVIDER_VERIFIED';
}
