<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Le premier événement de toute timeline : les PV de départ des deux combattants, pour que
 * le client pose ses deux barres avant la première attaque. Sans lui, la première `Attack`
 * livrerait des PV restants sans jauge de départ à partir de laquelle descendre.
 */
final readonly class BattleStarted implements BattleEvent
{
    public function __construct(
        public int $playerHp,
        public int $enemyHp,
    ) {
    }
}
