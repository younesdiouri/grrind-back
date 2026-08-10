<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Attribute;

/**
 * Marque une écriture métier comme rejouable : le contrôleur exigera un en-tête
 * `Idempotency-Key` et ne s'exécutera qu'une fois par clé.
 *
 * Déclaratif à dessein. La protection est une propriété de la route, pas une étape
 * que le contrôleur exécute — un contrôleur qui l'oublierait n'aurait aucun moyen de
 * s'en apercevoir, alors qu'une route non annotée se voit à la lecture.
 *
 * La route doit être authentifiée : la clé est portée par un joueur, jamais globale.
 *
 * @see IdempotencyListener
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Idempotent
{
}
