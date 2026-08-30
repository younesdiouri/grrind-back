<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Ce qui a déclenché un tirage — les deux points d'entrée du #28, câblés au #226 (import)
 * et au #227 (combat). Fermé à deux valeurs : un tirage n'a jamais d'autre origine que
 * « le joueur vient de terminer une séance » ou « le joueur vient de gagner un combat ».
 */
enum LootRollOrigin: string
{
    case Workout = 'WORKOUT';
    case Battle = 'BATTLE';
}
