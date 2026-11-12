<?php

declare(strict_types=1);

namespace App\Tests\Training\Domain;

use App\Shared\Domain\Timezone;
use App\Training\Domain\StatsPeriod;
use App\Training\Domain\WorkoutStats;
use App\Training\Domain\WorkoutStatsWindow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkoutStatsTest extends TestCase
{
    #[DataProvider('comparisons')]
    public function testVolumeComparison(int $seconds, int $before, ?int $change, ?string $grade): void
    {
        $timezone = Timezone::utc();
        $window = WorkoutStatsWindow::containing(StatsPeriod::Week, new DateTimeImmutable('2026-01-06'), $timezone);
        $stats = new WorkoutStats(StatsPeriod::Week, $timezone, $window, [
            ['date' => '2025-12-29', 'discipline' => 'RUNNING', 'seconds' => $before, 'sessions' => 1],
            ['date' => '2026-01-05', 'discipline' => 'RUNNING', 'seconds' => $seconds, 'sessions' => 1],
        ]);

        self::assertSame($change, $stats->change);
        self::assertSame($grade, $stats->grade?->value);
    }

    /** @return iterable<string, array{int, int, ?int, ?string}> */
    public static function comparisons(): iterable
    {
        yield 'no reference' => [1200, 0, null, null];
        yield 'both empty' => [0, 0, null, null];
        yield 'A boundary' => [1200, 1000, 20, 'A'];
        yield 'B below A' => [1199, 1000, 20, 'B'];
        yield 'B boundary' => [1000, 1000, 0, 'B'];
        yield 'C below B' => [999, 1000, 0, 'C'];
        yield 'C boundary' => [800, 1000, -20, 'C'];
        yield 'D below C' => [799, 1000, -20, 'D'];
        yield 'D boundary' => [500, 1000, -50, 'D'];
        yield 'E below D' => [499, 1000, -50, 'E'];
        yield 'no volume this period' => [0, 1000, -100, 'E'];
        yield 'JS negative half rounds toward positive infinity' => [700, 800, -12, 'C'];
    }
}
