<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * `$actor` rejoue immédiatement. Émis **après** l'`Attack` qui l'a déclenché et **avant**
 * celle qu'il accorde — voir le docblock de {@see BattleEvent} pour pourquoi cet ordre
 * n'est pas négociable : l'inverser ferait apparaître un tour bonus avant sa cause.
 */
final readonly class ExtraTurn implements BattleEvent
{
    public function __construct(
        public Actor $actor,
    ) {
    }
}
