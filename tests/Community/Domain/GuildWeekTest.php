<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\GuildWeek;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuildWeekTest extends TestCase
{
    #[DataProvider('weeks')]
    public function testParisCalendarWeekIncludesMondayAndHandlesDst(string $now, string $start, string $end, int $hours): void
    {
        $week = GuildWeek::containing(new DateTimeImmutable($now));
        self::assertSame($start, $week->startsAt->format('c'));
        self::assertSame($end, $week->endsAt->format('c'));
        self::assertSame($hours * 3600, $week->endsAt->getTimestamp() - $week->startsAt->getTimestamp());
    }

    /** @return iterable<string, array{string, string, string, int}> */
    public static function weeks(): iterable
    {
        yield 'monday midnight' => ['2026-10-26T00:00:00+01:00', '2026-10-26T00:00:00+01:00', '2026-11-02T00:00:00+01:00', 168];
        yield 'sunday just before midnight' => ['2026-10-25T22:59:59Z', '2026-10-19T00:00:00+02:00', '2026-10-26T00:00:00+01:00', 169];
        yield 'utc sunday is already monday in Paris' => ['2026-10-25T23:00:00Z', '2026-10-26T00:00:00+01:00', '2026-11-02T00:00:00+01:00', 168];
        yield 'spring forward' => ['2026-03-29T10:00:00Z', '2026-03-23T00:00:00+01:00', '2026-03-30T00:00:00+02:00', 167];
        yield 'year boundary' => ['2027-01-01T12:00:00Z', '2026-12-28T00:00:00+01:00', '2027-01-04T00:00:00+01:00', 168];
    }
}
