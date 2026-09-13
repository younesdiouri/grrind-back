<?php

declare(strict_types=1);

namespace App\Tests\Combat\Domain;

use App\Combat\Domain\StatCombination;
use App\Combat\Domain\StatFormula;
use App\Combat\Domain\StatSource;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class StatFormulaTest extends TestCase
{
    public function testAllCombinationsUseExactIntegerArithmetic(): void
    {
        $attributes = ['strength' => 9, 'endurance' => 4];
        self::assertSame(9, new StatFormula(StatSource::Strength, StatCombination::Single)->score($attributes));
        self::assertSame(13, new StatFormula(StatSource::Strength, StatCombination::Sum, StatSource::Endurance)->score($attributes));
        self::assertSame(6, new StatFormula(StatSource::Strength, StatCombination::ArithmeticMean, StatSource::Endurance)->score($attributes));
        self::assertSame(6, new StatFormula(StatSource::Strength, StatCombination::GeometricMean, StatSource::Endurance)->score($attributes));
        self::assertSame(\PHP_INT_MAX, new StatFormula(StatSource::Strength, StatCombination::ArithmeticMean, StatSource::Endurance)->score(['strength' => \PHP_INT_MAX, 'endurance' => \PHP_INT_MAX]));
    }

    public function testASumThatCannotBeRepresentedIsRejected(): void
    {
        $this->expectException(OverflowException::class);
        new StatFormula(StatSource::Strength, StatCombination::Sum, StatSource::Endurance)->score(['strength' => \PHP_INT_MAX, 'endurance' => 1]);
    }

    public function testAPairRequiresTwoDistinctAttributes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new StatFormula(StatSource::Strength, StatCombination::Sum, StatSource::Strength);
    }
}
