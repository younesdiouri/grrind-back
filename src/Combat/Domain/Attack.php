<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Un tour d'attaque. `$attacker` désigne qui frappe — sa cible est l'autre valeur d'
 * {@see Actor}, jamais portée ici, un combat n'en ayant que deux.
 *
 * `$targetHpRemaining` est l'état **après** ce coup, jamais un delta : c'est la même règle
 * que les jauges du `RewardSummary` — le client ne soustrait jamais rien, il pose la barre
 * là où le combat en est.
 */
final readonly class Attack implements BattleEvent
{
    public function __construct(
        public Actor $attacker,
        public int $damage,
        public int $targetHpRemaining,
    ) {
    }
}
