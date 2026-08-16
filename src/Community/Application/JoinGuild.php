<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Le joueur vient du jeton, le code du corps. **Aucun identifiant de guilde** : c'est le
 * code qui désigne la guilde, et lui seul — sans quoi il suffirait d'un UUID pour entrer.
 */
final readonly class JoinGuild
{
    public function __construct(
        public Uuid $playerId,
        public string $code,
    ) {
    }
}
