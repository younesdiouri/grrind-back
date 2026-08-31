<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Ce qui a déclenché un tirage — les trois points d'entrée du #28 (import, #226), du #227
 * (combat) et du #230 (un coffre qu'on ouvre). Fermé à trois valeurs : un tirage n'a jamais
 * d'autre origine que « le joueur vient de terminer une séance », « le joueur vient de
 * gagner un combat », ou « le joueur vient d'ouvrir un coffre ».
 */
enum LootRollOrigin: string
{
    case Workout = 'WORKOUT';
    case Battle = 'BATTLE';
    case Chest = 'CHEST';
}
