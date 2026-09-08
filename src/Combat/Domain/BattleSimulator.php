<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use App\Shared\Application\GameRulesets;
use Random\Randomizer;

/** Moteur v2 pur : actions planifiées en ticks, chaîne entière au même instant.
 * Seules les égalités de dates alternent la priorité (joueur en premier).
 * maxAttacks borne toutes les tentatives, même une chaîne infinie de coups esquivés.
 * La comparaison finale des ratios est entière, sans produit potentiellement débordant. */
final readonly class BattleSimulator
{
    public function __construct(private CombatRules|GameRulesets $rules)
    {
    }

    public function fight(Fighter $player, Fighter $enemy, Randomizer $rng): BattleOutcome
    {
        $rules = $this->rules();
        $resolver = new HitResolver($rules);
        $fighters = [$player, $enemy];
        $hp = [$player->hp, $enemy->hp];
        $next = [0, 0];
        $attempts = [0, 0];
        $tiePriority = 0;
        $actions = 0;
        $attacks = 0;
        $tick = 0;
        $timeline = [new BattleStarted($hp[0], $hp[1])];
        while ($hp[0] > 0 && $hp[1] > 0 && $attacks < $rules->maxAttacks) {
            if ($next[0] === $next[1]) {
                $index = $tiePriority;
                $tiePriority = 1 - $tiePriority;
            } else {
                $index = $next[0] < $next[1] ? 0 : 1;
            }
            $target = 1 - $index;
            $actor = 0 === $index ? Actor::Player : Actor::Enemy;
            $tick = $next[$index];
            ++$actions;
            while (true) {
                ++$attacks;
                $event = $resolver->resolve($fighters[$index], $fighters[$target], $actor, $hp[$target], $attempts[$index], $tick, $actions, $attacks, $rng);
                ++$attempts[$index];
                $timeline[] = $event;
                if ($event instanceof Attack) {
                    $hp[$target] = $event->targetHpRemaining;
                }
                if (0 === $hp[$target] || $attacks === $rules->maxAttacks || $rng->getInt(0, 999) >= $fighters[$index]->comboPermille) {
                    break;
                }
                $timeline[] = new Combo($actor, $tick, $actions, $attacks + 1);
            }
            $next[$index] = $tick + max(1, CombatMath::scale($rules->baseCooldownTicks, 1000 - $fighters[$index]->cooldownReductionPermille));
        }
        $reason = 0 === $hp[0] || 0 === $hp[1] ? BattleEndReason::Knockout : BattleEndReason::AttackLimit;
        $result = CombatMath::compare($hp[0], $player->hp, $hp[1], $enemy->hp) >= 0 ? BattleResult::Victory : BattleResult::Defeat;
        $timeline[] = new BattleFinished($result, $reason, $actions, $attacks, $tick);

        return new BattleOutcome($result, $timeline, $attacks, $actions, $tick, $reason);
    }

    private function rules(): CombatRules
    {
        if ($this->rules instanceof CombatRules) {
            return $this->rules;
        }

        $snapshot = $this->rules->snapshot();
        /** @var array{combat: array{fighter: array<string, int>}} $snapshot */
        /** @var array<string, int> $fighter */
        $fighter = $snapshot['combat']['fighter'];

        return CombatRules::fromSnapshot($fighter);
    }
}
