<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/** actionCount inclut les actions régulières ; attackCount inclut chaque tentative de Combo/esquive. */
final readonly class BattleOutcome
{
    /** @param list<BattleEvent> $timeline */
    public function __construct(
        public BattleResult $result,
        public array $timeline,
        public int $attackCount,
        public int $actionCount = 1,
        public int $elapsedTicks = 0,
        public BattleEndReason $endReason = BattleEndReason::Knockout,
    ) {
    }
}
