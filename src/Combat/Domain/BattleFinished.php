<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Le dernier événement de toute timeline. `$result` est aussi porté par
 * {@see BattleOutcome::$result} — la même redondance de confort que `xp.awarded` face au
 * `breakdown` du `RewardSummary` : le résultat se lit sans avoir à rejouer toute la
 * timeline pour savoir comment le combat s'est terminé.
 */
final readonly class BattleFinished implements BattleEvent
{
    public function __construct(
        public BattleResult $result,
    ) {
    }
}
