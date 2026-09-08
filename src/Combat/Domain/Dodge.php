<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/** Temps logique en ticks ; indices globaux à partir de 1. Les dégâts exposent chaque
 * étape entière : fatigue, critique, mitigation, guard, minimum ; HP après application. */
final readonly class Dodge implements BattleEvent
{
    public function __construct(
        public Actor $attacker,
        public int $powerPermille = 1000,
        public int $atTick = 0,
        public int $actionIndex = 1,
        public int $attackIndex = 1,
    ) {
    }
}
