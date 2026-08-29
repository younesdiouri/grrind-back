<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * L'issue d'un combat, **du point de vue du joueur** — jamais de l'ennemi, qui n'a pas de
 * client à qui l'annoncer. `BattleSimulator::fight()` en rend toujours une : « le combat se
 * termine toujours avec un vainqueur » est une exigence du produit, pas une probabilité.
 */
enum BattleResult: string
{
    case Victory = 'VICTORY';
    case Defeat = 'DEFEAT';
}
