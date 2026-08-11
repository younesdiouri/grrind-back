<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\LocalDay;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La journée du joueur, pas celle du serveur. Sans ça, un joueur à Tokyo verrait son
 * plafond quotidien se réinitialiser à 9 h du matin et sa série se rompre en plein
 * après-midi.
 */
final class LocalDayTest extends TestCase
{
    #[DataProvider('midnights')]
    public function testDelimitsTheDayInThePlayerTimezone(string $instant, string $timezone, string $expectedDate, string $expectedStart): void
    {
        $day = LocalDay::containing(new DateTimeImmutable($instant), Timezone::fromString($timezone));

        self::assertSame($expectedDate, $day->date);
        self::assertSame($expectedStart, $day->startsAt->format('Y-m-d\TH:i:sP'));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function midnights(): iterable
    {
        // 23 h UTC est déjà le lendemain à Paris : la séance compte pour le 2 mars.
        yield 'Paris, juste avant minuit UTC' => [
            '2026-03-01T23:30:00+00:00', 'Europe/Paris', '2026-03-02', '2026-03-01T23:00:00+00:00',
        ];

        // 1 h UTC est encore la veille à New York.
        yield 'New York, juste après minuit UTC' => [
            '2026-03-02T01:00:00+00:00', 'America/New_York', '2026-03-01', '2026-03-01T05:00:00+00:00',
        ];

        // Tokyo est en avance de neuf heures : sa journée commence quand l'Europe dort.
        yield 'Tokyo, en pleine journée UTC' => [
            '2026-03-02T14:00:00+00:00', 'Asia/Tokyo', '2026-03-02', '2026-03-01T15:00:00+00:00',
        ];

        yield 'UTC, sans surprise' => [
            '2026-03-02T14:00:00+00:00', 'UTC', '2026-03-02', '2026-03-02T00:00:00+00:00',
        ];

        // Un fuseau à décalage non entier existe, et il ne doit pas être un cas à part.
        yield 'Katmandou, décalé de 5 h 45' => [
            '2026-03-01T19:00:00+00:00', 'Asia/Kathmandu', '2026-03-02', '2026-03-01T18:15:00+00:00',
        ];
    }

    public function testASpringForwardDayIsShorter(): void
    {
        // 29 mars 2026 : l'Europe passe à l'heure d'été à 2 h du matin. La journée fait
        // 23 heures. `+86400 secondes` aurait débordé sur le lendemain, et une séance du
        // 30 aurait compté dans le quota du 29.
        $day = LocalDay::containing(new DateTimeImmutable('2026-03-29T10:00:00+02:00'), Timezone::fromString('Europe/Paris'));

        self::assertSame('2026-03-29', $day->date);
        self::assertSame(23 * 3600, $day->lengthInSeconds());
    }

    public function testAFallBackDayIsLonger(): void
    {
        // 25 octobre 2026 : retour à l'heure d'hiver, la journée fait 25 heures. Une
        // journée tronquée à 24 h aurait exclu la dernière heure du décompte.
        $day = LocalDay::containing(new DateTimeImmutable('2026-10-25T10:00:00+01:00'), Timezone::fromString('Europe/Paris'));

        self::assertSame('2026-10-25', $day->date);
        self::assertSame(25 * 3600, $day->lengthInSeconds());
    }

    public function testTheDayIsAHalfOpenInterval(): void
    {
        $day = LocalDay::containing(new DateTimeImmutable('2026-03-02T12:00:00+01:00'), Timezone::fromString('Europe/Paris'));

        // Minuit appartient au jour qui commence, jamais à celui qui finit : sans cette
        // règle, une séance close pile à minuit compterait deux fois.
        self::assertTrue($day->contains($day->startsAt));
        self::assertFalse($day->contains($day->endsAt));
        self::assertTrue($day->contains($day->endsAt->modify('-1 second')));
    }

    public function testTwoConsecutiveDaysMeetWithoutOverlapping(): void
    {
        $timezone = Timezone::fromString('Europe/Paris');
        $day = LocalDay::containing(new DateTimeImmutable('2026-03-29T10:00:00+02:00'), $timezone);
        $previous = LocalDay::containing($day->startsAt->modify('-1 second'), $timezone);

        // Y compris en travers d'un changement d'heure : aucune seconde n'appartient à
        // deux journées, aucune n'appartient à aucune.
        self::assertSame('2026-03-28', $previous->date);
        self::assertEquals($day->startsAt, $previous->endsAt);
    }
}
