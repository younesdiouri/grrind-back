<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\XpBreakdown;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Le breakdown est la valeur que `XpCalculator` (#14) rendra et que le ledger
 * matérialise. Ce qui se vérifie ici est son arithmétique et son ordre — le contrat que
 * le client iOS animera.
 */
final class XpBreakdownTest extends TestCase
{
    public function testTheTotalIsTheSumOfItsLines(): void
    {
        $breakdown = new XpBreakdown(
            new XpBreakdownLine(XpBreakdownSource::Base, 90),
            new XpBreakdownLine(XpBreakdownSource::Streak, 18),
            new XpBreakdownLine(XpBreakdownSource::Item, 13),
        );

        self::assertSame(121, $breakdown->total());
    }

    public function testAGuardRailSubtracts(): void
    {
        $breakdown = new XpBreakdown(
            new XpBreakdownLine(XpBreakdownSource::Base, 200),
            new XpBreakdownLine(XpBreakdownSource::Diminishing, -80),
            new XpBreakdownLine(XpBreakdownSource::DailyCap, -20),
        );

        self::assertSame(100, $breakdown->total());
    }

    public function testTheTotalCanBeZero(): void
    {
        // Une séance entièrement rognée reste une séance : elle s'écrit au ledger, et
        // c'est le détail qui explique au joueur pourquoi elle n'a rien rapporté.
        $breakdown = new XpBreakdown(
            new XpBreakdownLine(XpBreakdownSource::Base, 60),
            new XpBreakdownLine(XpBreakdownSource::Diminishing, -60),
        );

        self::assertSame(0, $breakdown->total());
    }

    public function testKeepsTheOrderItWasGiven(): void
    {
        // Le client ne trie pas les lignes, il les joue.
        $breakdown = new XpBreakdown(
            new XpBreakdownLine(XpBreakdownSource::Streak, 18),
            new XpBreakdownLine(XpBreakdownSource::Base, 90),
        );

        self::assertSame(
            [XpBreakdownSource::Streak, XpBreakdownSource::Base],
            array_map(static fn (XpBreakdownLine $line): XpBreakdownSource => $line->source, $breakdown->lines),
        );
    }

    public function testNegatingMirrorsEveryLine(): void
    {
        $breakdown = new XpBreakdown(
            new XpBreakdownLine(XpBreakdownSource::Base, 90),
            new XpBreakdownLine(XpBreakdownSource::Diminishing, -30),
        );

        $reversal = $breakdown->negated();

        // Le joueur ne perd pas « 60 XP » sans raison : on lui reprend exactement ce qui
        // lui avait été donné, ligne par ligne — y compris ce qui lui avait été rogné.
        self::assertSame(-60, $reversal->total());
        self::assertSame([-90, 30], array_map(static fn (XpBreakdownLine $line): int => $line->amount, $reversal->lines));
        self::assertSame(0, $breakdown->total() + $reversal->total());
    }

    public function testRefusesAnAmountWithoutAnExplanation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new XpBreakdown();
    }
}
