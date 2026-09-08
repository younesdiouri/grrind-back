<?php

declare(strict_types=1);

namespace App\Tests\Combat\Domain;

use App\Combat\Domain\Actor;
use App\Combat\Domain\Attack;
use App\Combat\Domain\BattleEndReason;
use App\Combat\Domain\BattleSimulator;
use App\Combat\Domain\Combo;
use App\Combat\Domain\Dodge;
use App\Combat\Domain\Fighter;
use App\Combat\Domain\HitResolver;
use App\Tests\Combat\CombatRulesFixture;
use PHPUnit\Framework\TestCase;
use Random\Engine;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class CombatV2Test extends TestCase
{
    public function testRelativeCountersUseTheOpponentsRateAndExactBoundary(): void
    {
        $resolver = new HitResolver(CombatRulesFixture::rules());
        $attacker = new Fighter(100, 100, 0, 0, 0, criticalChancePermille: 300, precisionPermille: 200);
        $target = new Fighter(100, 10, 0, 0, 300, criticalResistancePermille: 200);
        $dodge = $resolver->resolve($attacker, $target, Actor::Player, 100, 0, 0, 1, 1, self::fixedRoll(239));
        self::assertInstanceOf(Dodge::class, $dodge);
        $hit = $resolver->resolve($attacker, $target, Actor::Player, 100, 0, 0, 1, 1, self::fixedRoll(240));
        self::assertInstanceOf(Attack::class, $hit);
        self::assertFalse($hit->critical);
        $noDodge = new Fighter(100, 10, 0, 0, 0, criticalResistancePermille: 200);
        $crit = $resolver->resolve($attacker, $noDodge, Actor::Player, 100, 0, 0, 1, 1, self::fixedRoll(239));
        self::assertInstanceOf(Attack::class, $crit);
        self::assertTrue($crit->critical);
    }

    public function testMaintenanceSlowsFatigueWithoutEverRaisingPower(): void
    {
        $resolver = new HitResolver(CombatRulesFixture::rules());
        $target = new Fighter(1000, 0, 0, 0, 0);
        $rested = new Fighter(100, 100, 0, 0, 0, maintenancePermille: 950);
        $ordinary = new Fighter(100, 100, 0, 0, 0);
        $previous = 1000;
        foreach ([0, 1, 10, 100, 9999] as $attempts) {
            $strong = $resolver->resolve($rested, $target, Actor::Player, 1000, $attempts, 0, 1, 1, self::fixedRoll(999));
            $weak = $resolver->resolve($ordinary, $target, Actor::Player, 1000, $attempts, 0, 1, 1, self::fixedRoll(999));
            self::assertLessThanOrEqual($previous, $strong->powerPermille);
            self::assertGreaterThanOrEqual($weak->powerPermille, $strong->powerPermille);
            self::assertGreaterThanOrEqual(400, $strong->powerPermille);
            $previous = $strong->powerPermille;
        }
    }

    public function testEqualDatesAlternatePriorityAndCountActionsSeparately(): void
    {
        $fighter = new Fighter(10000, 10, 0, 0, 0);
        $outcome = new BattleSimulator(CombatRulesFixture::rules(['max_attacks' => 6]))->fight($fighter, $fighter, self::rng());
        $attacks = array_values(array_filter($outcome->timeline, static fn ($e) => $e instanceof Attack));
        self::assertSame([Actor::Player, Actor::Enemy, Actor::Enemy, Actor::Player, Actor::Player, Actor::Enemy], array_column($attacks, 'attacker'));
        self::assertSame([0, 0, 1000, 1000, 2000, 2000], array_column($attacks, 'atTick'));
        self::assertSame(6, $outcome->attackCount);
        self::assertSame(6, $outcome->actionCount);
        self::assertSame(2000, $outcome->elapsedTicks);
        self::assertSame(BattleEndReason::AttackLimit, $outcome->endReason);
    }

    public function testFasterActionsDoNotPostponeOpponent(): void
    {
        $player = new Fighter(10000, 10, 0, 0, 0, cooldownReductionPermille: 500);
        $enemy = new Fighter(10000, 10, 0, 0, 0);
        $outcome = new BattleSimulator(CombatRulesFixture::rules(['max_attacks' => 5]))->fight($player, $enemy, self::rng());
        $attacks = array_values(array_filter($outcome->timeline, static fn ($e) => $e instanceof Attack));
        self::assertSame([0, 0, 500, 1000, 1000], array_column($attacks, 'atTick'));
        self::assertSame(Actor::Enemy, $attacks[3]->attacker);
    }

    public function testCritAndGuardCoexistWithStepwiseFloorAndFatigue(): void
    {
        $player = new Fighter(10000, 101, 0, 1000, 0, criticalChancePermille: 1000);
        $enemy = new Fighter(10000, 10, 300, 0, 0, guardPermille: 1000);
        $outcome = new BattleSimulator(CombatRulesFixture::rules(['max_attacks' => 2]))->fight($player, $enemy, self::rng());
        $attacks = array_values(array_filter($outcome->timeline, static fn ($e) => $e instanceof Attack));
        self::assertTrue($attacks[0]->critical);
        self::assertTrue($attacks[0]->guarded);
        self::assertSame(1000, $attacks[0]->powerPermille);
        self::assertSame(880, $attacks[1]->powerPermille);
        self::assertSame(52, $attacks[0]->damage); // 101 → 151 → 105 → 52
        self::assertSame(46, $attacks[1]->damage); // 101 → 88 → 132 → 92 → 46
        self::assertSame(1, $outcome->actionCount);
        self::assertCount(1, array_filter($outcome->timeline, static fn ($e) => $e instanceof Combo));
    }

    public function testDodgesConsumeFatigueAndBoundAnUnendingCombo(): void
    {
        $player = new Fighter(100, 1, 0, 1000, 0);
        $enemy = new Fighter(100, 1, 0, 0, 1000);
        $outcome = new BattleSimulator(CombatRulesFixture::rules(['max_attacks' => 200]))->fight($player, $enemy, self::rng());
        $dodges = array_values(array_filter($outcome->timeline, static fn ($e) => $e instanceof Dodge));
        self::assertCount(200, $dodges);
        self::assertSame(880, $dodges[1]->powerPermille);
        self::assertCount(199, array_filter($outcome->timeline, static fn ($e) => $e instanceof Combo));
        self::assertSame(0, $outcome->elapsedTicks);
    }

    public function testKoImmediatelyEndsComboAndSameSeedReproducesTimeline(): void
    {
        $player = new Fighter(100, 1000, 0, 1000, 0);
        $enemy = new Fighter(1, 1, 0, 0, 0);
        $simulator = new BattleSimulator(CombatRulesFixture::rules());
        $outcome = $simulator->fight($player, $enemy, self::rng());
        self::assertSame(BattleEndReason::Knockout, $outcome->endReason);
        self::assertSame(1, $outcome->attackCount);
        self::assertCount(0, array_filter($outcome->timeline, static fn ($e) => $e instanceof Combo));
        self::assertEquals($outcome, $simulator->fight($player, $enemy, self::rng()));
    }

    private static function fixedRoll(int $roll): Randomizer
    {
        return new Randomizer(new class($roll) implements Engine {
            public function __construct(private int $roll)
            {
            }

            public function generate(): string
            {
                return pack('V', $this->roll);
            }
        });
    }

    private static function rng(): Randomizer
    {
        return new Randomizer(new Xoshiro256StarStar(42));
    }
}
