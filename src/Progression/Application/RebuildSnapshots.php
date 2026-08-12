<?php

declare(strict_types=1);

namespace App\Progression\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Rejouer le ledger et remettre le snapshot d'accord avec lui.
 *
 * **`dryRun` est le mode qui sert le plus.** Réparer est facile ; ce qui est difficile est
 * de savoir qu'il y a quelque chose à réparer. Un cache qui ne sait pas se comparer à sa
 * source n'est pas un cache, c'est une seconde vérité qui diverge en silence — et un écart
 * découvert par un joueur qui lit un mauvais niveau se découvre trop tard.
 *
 * `userId` à `null` vaut « tout le monde ». Le cas nominal est bien celui-là : on audite la
 * base, on ne va voir un compte en particulier que quand on a déjà un signalement.
 */
final readonly class RebuildSnapshots
{
    public function __construct(
        public ?Uuid $userId = null,
        public bool $dryRun = false,
    ) {
    }
}
