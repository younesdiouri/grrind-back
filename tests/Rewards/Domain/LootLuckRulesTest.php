<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\LootLuckRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Le plancher et le plafond seuls, sans `LootRoller` — voir son test pour la composition
 * (somme des `LOOT_LUCK` actifs) et son application aux poids d'une table.
 */
final class LootLuckRulesTest extends TestCase
{
    public function testClampLeavesAValueInsideTheBoundsUntouched(): void
    {
        $rules = new LootLuckRules(0, 200);

        self::assertSame(80, $rules->clamp(80));
    }

    public function testClampNeverGoesBelowTheFloor(): void
    {
        $rules = new LootLuckRules(0, 200);

        self::assertSame(0, $rules->clamp(-1000));
    }

    public function testClampNeverGoesAboveTheCap(): void
    {
        $rules = new LootLuckRules(0, 200);

        self::assertSame(200, $rules->clamp(100_000));
    }

    public function testRefusesANegativeFloor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootLuckRules(-1, 200);
    }

    public function testRefusesACapUnderItsFloor(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LootLuckRules(50, 49);
    }
}
