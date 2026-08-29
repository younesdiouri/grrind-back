<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Ce que rend {@see BattleSimulator::fight()} : le verdict, la mise en scène, et le nombre
 * de tours joués. `$result` et `$turns` sont dérivables de `$timeline` — le dernier événement
 * est toujours un {@see BattleFinished} — mais les porter ici évite à l'appelant (#211,
 * persistance et réponse HTTP) de fouiller la timeline pour une valeur qu'il consomme
 * directement, même geste que les champs de confort du `RewardSummary`.
 */
final readonly class BattleOutcome
{
    /**
     * @param list<BattleEvent> $timeline
     */
    public function __construct(
        public BattleResult $result,
        public array $timeline,
        public int $turns,
    ) {
    }
}
