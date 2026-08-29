<?php

declare(strict_types=1);

namespace App\Combat\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Opposer un joueur à l'ennemi que le serveur choisit pour lui — ou, depuis le #219, à
 * celui que le joueur a nommé.
 *
 * Le joueur vient du jeton, jamais du corps — même règle que partout ailleurs.
 * `$enemyKey` est le champ additif annoncé au #211 : `null` laisse
 * {@see FightBattleHandler} choisir au niveau du joueur, exactement comme avant ; une clé
 * lui fait résoudre l'adversaire nommé — voir son docblock pour ce que « nommé » couvre.
 */
final readonly class FightBattle
{
    public function __construct(
        public Uuid $playerId,
        public ?string $enemyKey = null,
    ) {
    }
}
