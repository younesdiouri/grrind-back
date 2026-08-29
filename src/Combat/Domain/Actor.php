<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Qui, dans un combat à deux : le joueur, ou l'ennemi qui lui est opposé.
 *
 * Un combat n'oppose jamais plus de deux combattants en V1 — pas d'IA ni de groupe — donc
 * ce vocabulaire suffit à désigner l'attaquant d'un tour et, par déduction, sa cible :
 * l'autre valeur. {@see BattleSimulator} ne le fait jamais porter de stats ; c'est
 * {@see Fighter} qui en porte, un par valeur.
 */
enum Actor: string
{
    case Player = 'PLAYER';
    case Enemy = 'ENEMY';

    public function opponent(): self
    {
        return self::Player === $this ? self::Enemy : self::Player;
    }
}
