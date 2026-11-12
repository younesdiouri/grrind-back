<?php

declare(strict_types=1);

namespace App\Tests\Training\Domain;

use App\Shared\Domain\Timezone;
use App\Training\Domain\StatsPeriod;
use App\Training\Domain\WorkoutStatsWindow;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkoutStatsWindowTest extends TestCase
{
    #[DataProvider('windows')]
    public function testCivilWindows(
        string $period,
        string $now,
        string $timezone,
        string $start,
        string $end,
        string $observedEnd,
        string $previousStart,
        string $previousEnd,
        int $days,
    ): void {
        $window = WorkoutStatsWindow::containing(StatsPeriod::from($period), new DateTimeImmutable($now), Timezone::fromString($timezone));

        self::assertSame($start, $window->start->format('c'));
        self::assertSame($end, $window->end->format('c'));
        self::assertSame($observedEnd, $window->observedEnd->format('c'));
        self::assertSame($previousStart, $window->previousStart->format('c'));
        self::assertSame($previousEnd, $window->previousEnd->format('c'));
        self::assertSame($days, $window->days);
    }

    /** @return iterable<string, array{string, string, string, string, string, string, string, string, int}> */
    public static function windows(): iterable
    {
        yield 'Monday in Tokyo, still Sunday UTC' => ['week', '2026-01-04T16:00:00Z', 'Asia/Tokyo', '2026-01-05T00:00:00+09:00', '2026-01-12T00:00:00+09:00', '2026-01-06T00:00:00+09:00', '2025-12-29T00:00:00+09:00', '2025-12-30T00:00:00+09:00', 1];
        yield 'spring DST, seven civil days' => ['week', '2026-03-29T12:00:00Z', 'Europe/Paris', '2026-03-23T00:00:00+01:00', '2026-03-30T00:00:00+02:00', '2026-03-30T00:00:00+02:00', '2026-03-16T00:00:00+01:00', '2026-03-23T00:00:00+01:00', 7];
        yield 'autumn DST' => ['week', '2026-10-25T12:00:00Z', 'Europe/Paris', '2026-10-19T00:00:00+02:00', '2026-10-26T00:00:00+01:00', '2026-10-26T00:00:00+01:00', '2026-10-12T00:00:00+02:00', '2026-10-19T00:00:00+02:00', 7];
        yield 'March clips comparison to February' => ['month', '2026-03-31T12:00:00Z', 'UTC', '2026-03-01T00:00:00+00:00', '2026-04-01T00:00:00+00:00', '2026-04-01T00:00:00+00:00', '2026-02-01T00:00:00+00:00', '2026-03-01T00:00:00+00:00', 31];
        yield 'short current month compares only 28 days' => ['month', '2026-02-28T12:00:00Z', 'UTC', '2026-02-01T00:00:00+00:00', '2026-03-01T00:00:00+00:00', '2026-03-01T00:00:00+00:00', '2026-01-01T00:00:00+00:00', '2026-01-29T00:00:00+00:00', 28];
        yield 'first day of year' => ['year', '2026-01-01T00:00:00Z', 'UTC', '2026-01-01T00:00:00+00:00', '2027-01-01T00:00:00+00:00', '2026-01-02T00:00:00+00:00', '2025-01-01T00:00:00+00:00', '2025-01-02T00:00:00+00:00', 1];
        yield 'leap day compares elapsed days, not matching date' => ['year', '2024-03-01T12:00:00Z', 'UTC', '2024-01-01T00:00:00+00:00', '2025-01-01T00:00:00+00:00', '2024-03-02T00:00:00+00:00', '2023-01-01T00:00:00+00:00', '2023-03-03T00:00:00+00:00', 61];
        yield 'leap year clips to previous year' => ['year', '2024-12-31T12:00:00Z', 'UTC', '2024-01-01T00:00:00+00:00', '2025-01-01T00:00:00+00:00', '2025-01-01T00:00:00+00:00', '2023-01-01T00:00:00+00:00', '2024-01-01T00:00:00+00:00', 366];
    }
}
