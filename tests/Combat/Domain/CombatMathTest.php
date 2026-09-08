<?php

declare(strict_types=1);

namespace App\Tests\Combat\Domain;

use App\Combat\Domain\CombatMath;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class CombatMathTest extends TestCase
{
    public function testLargeScalingAndRatiosNeverUseFloatingPoint(): void
    {
        self::assertSame(\PHP_INT_MAX, CombatMath::scale(\PHP_INT_MAX, 1000));
        self::assertSame(1, CombatMath::compare(\PHP_INT_MAX - 1, \PHP_INT_MAX, \PHP_INT_MAX - 2, \PHP_INT_MAX - 1));
        $this->expectException(OverflowException::class);
        CombatMath::scale(\PHP_INT_MAX, 1500);
    }

    public function testPairsUseExactGeometricMeanWithoutOverflow(): void
    {
        self::assertSame(0, CombatMath::pair(0, \PHP_INT_MAX));
        self::assertSame(6, CombatMath::pair(4, 9));
        self::assertSame(6, CombatMath::pair(5, 9));
        self::assertSame(\PHP_INT_MAX, CombatMath::pair(\PHP_INT_MAX, \PHP_INT_MAX));
        self::assertSame(3037000499, CombatMath::pair(1, \PHP_INT_MAX));
        self::assertSame(\PHP_INT_MAX - 2, CombatMath::pair(\PHP_INT_MAX, \PHP_INT_MAX - 2));
    }

    public function testSaturationIsMonotoneAndBoundedAtLargeValues(): void
    {
        self::assertSame(0, CombatMath::rate(0, 350, 10000));
        self::assertSame(175, CombatMath::rate(10000, 350, 10000));
        self::assertSame(349, CombatMath::rate(\PHP_INT_MAX, 350, 10000));
        $previous = 0;
        foreach ([1, 100, 1000, 10000, 100000, \PHP_INT_MAX] as $score) {
            $rate = CombatMath::rate($score, 350, 10000);
            self::assertGreaterThanOrEqual($previous, $rate);
            self::assertLessThan(350, $rate);
            $previous = $rate;
        }
    }
}
