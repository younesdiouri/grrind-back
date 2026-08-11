<?php

declare(strict_types=1);

namespace App\Shared\UI\Http;

use Attribute;

/**
 * Marque une écriture métier comme rejouable : la route exigera un en-tête
 * `Idempotency-Key` et ne s'exécutera qu'une fois par clé.
 *
 * Déclaratif à dessein — une route non annotée se voit à la lecture, un contrôleur qui
 * oublierait l'appel ne se voit pas. La route doit être authentifiée : la clé est
 * portée par un joueur, jamais globale.
 *
 * @see IdempotencyListener
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Idempotent
{
}
