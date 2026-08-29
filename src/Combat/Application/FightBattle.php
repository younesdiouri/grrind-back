<?php

declare(strict_types=1);

namespace App\Combat\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Opposer un joueur à l'ennemi que le serveur choisit pour lui.
 *
 * Le joueur vient du jeton, jamais du corps — même règle que partout ailleurs. Pas de
 * champ pour choisir l'adversaire : {@see FightBattleHandler} le prend au niveau du
 * joueur, voir le ticket #211 pour pourquoi c'est additif plutôt qu'un manque.
 */
final readonly class FightBattle
{
    public function __construct(
        public Uuid $playerId,
    ) {
    }
}
