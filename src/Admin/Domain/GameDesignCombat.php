<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Combat\Domain\Actor;
use App\Combat\Domain\Attack;
use App\Combat\Domain\BattleEndReason;
use App\Combat\Domain\BattleResult;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\CombatRules;
use App\Combat\Domain\CombatSnapshot;
use App\Combat\Domain\EnemyCatalog;
use App\Combat\Domain\Fighter;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Aucun handler métier : chaque duel utilise le moteur pur. Les graines sont communes aux
 * deux versions et seule la première timeline est conservée pour borner la mémoire.
 *
 * @phpstan-import-type ProfileInput from GameDesignProfile
 */
final class GameDesignCombat
{
    public const int MAX_ATTEMPTS = 500000;

    /** @param array<string, mixed> $published
     * @param array<string, mixed> $draft
     */
    public static function maximumSamples(array $published, array $draft): int
    {
        /** @var array{combat: array{fighter: array<string, int>}} $published */
        /** @var array{combat: array{fighter: array<string, int>}} $draft */
        $cost = CombatRules::fromSnapshot($published['combat']['fighter'])->maxAttacks + CombatRules::fromSnapshot($draft['combat']['fighter'])->maxAttacks;

        return min(1000, intdiv(self::MAX_ATTEMPTS, $cost));
    }

    /** @param array<string, mixed> $published
     * @param array<string, mixed>                                                                                                                                                                                                                                                  $draft
     * @param ProfileInput                                                                                                                                                                                                                                                          $profile
     * @param array{hp: int, damage: int, mitigationPermille: int, comboPermille: int, dodgePermille: int, maintenancePermille: int, criticalChancePermille: int, guardPermille: int, criticalResistancePermille: int, cooldownReductionPermille: int, precisionPermille: int}|null $customEnemy
     *
     * @return array{published: array<string, mixed>, draft: array<string, mixed>}
     */
    public function compare(array $published, array $draft, array $profile, string $enemyKey, ?array $customEnemy, int $samples, int $seed): array
    {
        if ($samples < 1 || $samples > self::maximumSamples($published, $draft) || $seed < 0 || $seed > 2147482000) {
            throw new InvalidArgumentException('Nombre de combats ou graine hors limites. Le budget global est de 500 000 tentatives pour les deux versions.');
        }

        return [
            'published' => $this->series($published, $profile, $enemyKey, $customEnemy, $samples, $seed),
            'draft' => $this->series($draft, $profile, $enemyKey, $customEnemy, $samples, $seed),
        ];
    }

    /** @param array<string, mixed> $snapshot
     * @param ProfileInput                                                                                                                                                                                                                                                          $profile
     * @param array{hp: int, damage: int, mitigationPermille: int, comboPermille: int, dodgePermille: int, maintenancePermille: int, criticalChancePermille: int, guardPermille: int, criticalResistancePermille: int, cooldownReductionPermille: int, precisionPermille: int}|null $customEnemy
     *
     * @return array<string, mixed>
     */
    private function series(array $snapshot, array $profile, string $enemyKey, ?array $customEnemy, int $samples, int $seed): array
    {
        $rulesets = new FrozenGameRulesets($snapshot, GameRulesetVersion::of($snapshot));
        $resolved = GameDesignInputs::fighter($profile, $rulesets);
        $player = $resolved['fighter'];
        if (null !== $customEnemy) {
            $enemy = new Fighter(...$customEnemy);
        } else {
            $catalog = EnemyCatalog::runtime($rulesets);
            $definition = $catalog->findAvailable($enemyKey) ?? $catalog->findAvailableBoss($enemyKey)
                ?? throw new InvalidArgumentException('Adversaire indisponible dans la version '.$rulesets->version().' : '.$enemyKey);
            $enemy = new Fighter($definition->hp, $definition->damage, $definition->mitigationPermille, $definition->comboPermille, $definition->dodgePermille, $definition->maintenancePermille, $definition->criticalChancePermille, $definition->guardPermille, $definition->criticalResistancePermille, $definition->cooldownReductionPermille, $definition->precisionPermille);
        }
        $simulator = new BattleSimulator($rulesets);
        $wins = $limits = $ticks = $playerHp = $enemyHp = 0;
        $histogram = ['0 %' => 0, '1–25 %' => 0, '26–50 %' => 0, '51–75 %' => 0, '76–100 %' => 0];
        $detail = [];
        for ($index = 0; $index < $samples; ++$index) {
            $outcome = $simulator->fight($player, $enemy, new Randomizer(new Mt19937($seed + $index)));
            $remaining = [$player->hp, $enemy->hp];
            foreach ($outcome->timeline as $event) {
                if ($event instanceof Attack) {
                    $remaining[Actor::Player === $event->attacker ? 1 : 0] = $event->targetHpRemaining;
                }
            }
            $wins += BattleResult::Victory === $outcome->result ? 1 : 0;
            $limits += BattleEndReason::AttackLimit === $outcome->endReason ? 1 : 0;
            $ticks += $outcome->elapsedTicks;
            $playerHp += $remaining[0];
            $enemyHp += $remaining[1];
            $percent = intdiv($remaining[0] * 100, $player->hp);
            $bucket = 0 === $remaining[0] ? '0 %' : ($percent <= 25 ? '1–25 %' : ($percent <= 50 ? '26–50 %' : ($percent <= 75 ? '51–75 %' : '76–100 %')));
            ++$histogram[$bucket];
            if (0 === $index) {
                $detail = ['result' => $outcome->result->value, 'endReason' => $outcome->endReason->value, 'elapsedTicks' => $outcome->elapsedTicks, 'attackCount' => $outcome->attackCount, 'playerHp' => $remaining[0], 'enemyHp' => $remaining[1], 'events' => array_map(CombatSnapshot::event(...), $outcome->timeline)];
            }
        }

        return ['version' => $rulesets->version(), 'samples' => $samples, 'seed' => $seed, 'wins' => $wins, 'defeats' => $samples - $wins, 'limits' => $limits, 'totalTicks' => $ticks, 'totalPlayerHp' => $playerHp, 'totalEnemyHp' => $enemyHp, 'histogram' => $histogram, 'attributes' => $resolved['attributes'], 'player' => CombatSnapshot::fighter($player), 'enemy' => CombatSnapshot::fighter($enemy), 'detail' => $detail];
    }
}
