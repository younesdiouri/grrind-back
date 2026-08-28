<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\QuietHours;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * La plage calme, sans aucune infra — le fuseau IANA suffit à faire varier l'heure locale
 * d'un même instant UTC.
 */
final class QuietHoursTest extends TestCase
{
    public function testAnHourInsideAnOvernightRangeIsQuiet(): void
    {
        // 23h à Paris, dans une plage 22h → 8h.
        $hours = new QuietHours(22, 8);

        self::assertTrue($hours->contains(new DateTimeImmutable('2026-08-19T21:00:00+00:00'), Timezone::fromString('Europe/Paris')));
    }

    public function testAnHourPastMidnightInsideAnOvernightRangeIsQuiet(): void
    {
        // 3h du matin à Paris : la plage franchit minuit, ça reste calme.
        $hours = new QuietHours(22, 8);

        self::assertTrue($hours->contains(new DateTimeImmutable('2026-08-19T01:00:00+00:00'), Timezone::fromString('Europe/Paris')));
    }

    public function testAnHourOutsideTheRangeIsNotQuiet(): void
    {
        // Midi à Paris : loin des deux bornes.
        $hours = new QuietHours(22, 8);

        self::assertFalse($hours->contains(new DateTimeImmutable('2026-08-19T10:00:00+00:00'), Timezone::fromString('Europe/Paris')));
    }

    public function testTheStartHourIsIncludedAndTheEndHourIsNot(): void
    {
        $hours = new QuietHours(22, 8);

        self::assertTrue($hours->contains(new DateTimeImmutable('2026-08-19T22:00:00+00:00'), Timezone::fromString('Europe/Paris')), 'La borne de début est incluse.');
        self::assertFalse($hours->contains(new DateTimeImmutable('2026-08-19T08:00:00+00:00'), Timezone::fromString('Europe/Paris')), 'La borne de fin, elle, ne l\'est pas — sinon 8h et 8h59 auraient un sort différent.');
    }

    /**
     * Le fuseau du destinataire décide, jamais celui de l'instant fourni : la même
     * séance, créditée à la même seconde UTC, réveille Paris et pas Tokyo.
     */
    public function testTheSameInstantDiffersByTimezone(): void
    {
        $hours = new QuietHours(22, 8);
        $instant = new DateTimeImmutable('2026-08-19T23:00:00+00:00');

        self::assertTrue($hours->contains($instant, Timezone::fromString('Europe/Paris')), '1h du matin à Paris.');
        self::assertFalse($hours->contains($instant, Timezone::fromString('Asia/Tokyo')), '8h du matin à Tokyo, hors plage.');
    }

    public function testIdenticalBoundsDisableTheQuietWindow(): void
    {
        $hours = new QuietHours(9, 9);

        self::assertFalse($hours->contains(new DateTimeImmutable('2026-08-19T09:00:00+00:00'), Timezone::fromString('Europe/Paris')));
    }

    public function testAnInstantOutsideTheRangeIsItsOwnWakeUpTime(): void
    {
        // Rien à reporter : le message part maintenant. Rendre `$instant` tel quel évite à
        // l'appelant un `if` de plus, et un `if` de plus est un `if` qu'on oublie.
        $hours = new QuietHours(22, 8);
        $noon = new DateTimeImmutable('2026-08-19T10:00:00+00:00');

        self::assertEquals($noon, $hours->endsAfter($noon, Timezone::fromString('Europe/Paris')));
    }

    public function testAnInstantBeforeMidnightWakesUpTheNextMorning(): void
    {
        // 23h à Paris : la plage franchit minuit, donc la sortie est le lendemain 8h.
        $hours = new QuietHours(22, 8);

        self::assertSame(
            '2026-08-20 08:00:00',
            $hours->endsAfter(new DateTimeImmutable('2026-08-19T21:00:00+00:00'), Timezone::fromString('Europe/Paris'))
                ->setTimezone(new DateTimeZone('Europe/Paris'))
                ->format('Y-m-d H:i:s'),
        );
    }

    public function testAnInstantPastMidnightWakesUpTheSameMorning(): void
    {
        // 3h du matin à Paris : la sortie est dans cinq heures, pas dans vingt-neuf.
        $hours = new QuietHours(22, 8);

        self::assertSame(
            '2026-08-19 08:00:00',
            $hours->endsAfter(new DateTimeImmutable('2026-08-19T01:00:00+00:00'), Timezone::fromString('Europe/Paris'))
                ->setTimezone(new DateTimeZone('Europe/Paris'))
                ->format('Y-m-d H:i:s'),
        );
    }

    public function testTheWakeUpTimeIsReadInTheRecipientTimezone(): void
    {
        // Le même instant UTC : nuit à Tokyo, plein après-midi à Paris. Un seul des deux se
        // reporte, et c'est toute la raison d'être du #194 — 20h à Paris est 3h à Tokyo
        // *toutes les semaines*.
        $hours = new QuietHours(22, 8);
        $instant = new DateTimeImmutable('2026-08-19T18:00:00+00:00');

        self::assertNotEquals($instant, $hours->endsAfter($instant, Timezone::fromString('Asia/Tokyo')));
        self::assertEquals($instant, $hours->endsAfter($instant, Timezone::fromString('Europe/Paris')));
    }

    public function testWithoutAConfiguredRangeNothingIsEverPostponed(): void
    {
        // Un réglage à zéro ne doit jamais rendre la guilde muette — ni la faire attendre.
        $hours = new QuietHours(0, 0);
        $midnight = new DateTimeImmutable('2026-08-19T00:00:00+00:00');

        self::assertEquals($midnight, $hours->endsAfter($midnight, Timezone::fromString('Europe/Paris')));
    }
}
