<?php

declare(strict_types=1);

namespace App\Progression\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Choisir le titre qu'on affiche — ou n'en afficher aucun.
 *
 * `null` est une valeur, pas une absence de commande : « je retire mon titre » est un geste
 * que le joueur peut vouloir, et il ne mérite pas une seconde route.
 */
final readonly class SelectTitle
{
    public function __construct(
        public Uuid $userId,
        public ?string $titleId,
    ) {
    }
}
