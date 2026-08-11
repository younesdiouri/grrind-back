<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * Distinct de {@see SessionSource} : la source dit *qui* a produit la donnée, le crédit
 * dit ce que le moteur en fait. Les deux se confondent en v1 et divergeront quand une
 * séance déclarée sera rapprochée après coup d'une activité du fournisseur.
 *
 * Le sens est croissant : une séance ne perd jamais son crédit.
 */
enum TrustLevel: string
{
    case Declared = 'DECLARED';
    case ProviderVerified = 'PROVIDER_VERIFIED';
}
