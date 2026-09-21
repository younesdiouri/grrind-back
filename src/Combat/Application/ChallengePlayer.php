<?php

declare(strict_types=1);

namespace App\Combat\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Défier un co-équipier (#283).
 *
 * Le défieur vient du jeton, jamais du corps — même règle que partout ailleurs. Le défié
 * vient du `{id}` de la route : c'est la seule route de `Combat` qui prend un identifiant de
 * compte, et elle le prend pour servir les données de **quelqu'un d'autre**, comme
 * `GET /api/players/{id}` — voir le docblock de {@see ChallengePlayerHandler} pour ce qui la
 * garde.
 */
final readonly class ChallengePlayer
{
    public function __construct(
        public Uuid $challengerId,
        public Uuid $opponentId,
    ) {
    }
}
