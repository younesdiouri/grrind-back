<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\DailyLoad;
use App\Progression\Domain\DiminishingReturns;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Domain\XpBreakdownSource;
use App\Progression\Domain\XpCalculator;
use App\Progression\Domain\XpRates;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le calcul par table de cas. Aucune infra : c'est tout l'intérêt d'une fonction pure, et
 * c'est ce qui permettra de rejouer un calcul de l'an dernier pour expliquer un montant.
 *
 * Le barème de test est fixe et indépendant de celui qui est livré — une heure de course
 * vaut 90, ce qui donne au socle les chiffres de l'exemple du ticket.
 */
final class XpCalculatorTest extends TestCase
{
    /**
     * @param list<Modifier>                      $modifiers
     * @param list<array{XpBreakdownSource, int}> $expectedLines
     */
    #[DataProvider('cases')]
    public function testCalculates(int $durationSeconds, array $modifiers, array $expectedLines, int $expectedTotal): void
    {
        $award = self::calculator()->calculate(Discipline::Running, $durationSeconds, $modifiers, DailyLoad::untouched());

        self::assertSame($expectedLines, self::linesOf($award->breakdown->lines));
        self::assertSame($expectedTotal, $award->amount());
    }

    /**
     * @return iterable<string, array{int, list<Modifier>, list<array{XpBreakdownSource, int}>, int}>
     */
    public static function cases(): iterable
    {
        yield 'le socle seul' => [
            3600,
            [],
            [[XpBreakdownSource::Base, 90]],
            90,
        ];

        // L'exemple du ticket : « 90 base, +18 streak, +13 bottes ».
        yield 'streak et objet, additifs sur le socle' => [
            3600,
            [self::bonus(ModifierSource::Streak, 20), self::bonus(ModifierSource::Item, 15)],
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::Streak, 18], [XpBreakdownSource::Item, 13]],
            121,
        ];

        // Additif, pas multiplicatif : 90 × 1,20 × 1,15 vaudrait 124.
        yield 'la composition n\'est pas multiplicative' => [
            3600,
            [self::bonus(ModifierSource::Streak, 20), self::bonus(ModifierSource::Item, 15)],
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::Streak, 18], [XpBreakdownSource::Item, 13]],
            121,
        ];

        // Cumulé d'abord, arrondi ensuite : deux troncatures successives auraient donné
        // 9 + 4 = 13 ici, mais 4 + 4 = 8 au lieu de 9 sur un socle de 90 à 5 % + 5 %.
        yield 'deux objets ne comptent que pour une ligne' => [
            3600,
            [self::bonus(ModifierSource::Item, 5), self::bonus(ModifierSource::Item, 5)],
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::Item, 9]],
            99,
        ];

        yield 'un bonus trop petit pour peser n\'occupe pas de ligne' => [
            600, // socle de 15
            [self::bonus(ModifierSource::Skill, 5)], // 0,75 point, tronqué à 0
            [[XpBreakdownSource::Base, 15]],
            15,
        ];

        yield 'un malus retranche' => [
            3600,
            [self::bonus(ModifierSource::League, -10)],
            [[XpBreakdownSource::Base, 90], [XpBreakdownSource::League, -9]],
            81,
        ];

        // Deux modificateurs d'une même source qui s'annulent ne laissent pas de trace :
        // il n'y a rien à expliquer au joueur.
        yield 'des bonus qui s\'annulent' => [
            3600,
            [self::bonus(ModifierSource::Skill, 10), self::bonus(ModifierSource::Skill, -10)],
            [[XpBreakdownSource::Base, 90]],
            90,
        ];

        yield 'un socle nul n\'accorde aucun bonus' => [
            0,
            [self::bonus(ModifierSource::Streak, 50)],
            [[XpBreakdownSource::Base, 0]],
            0,
        ];

        yield 'la troncature du socle précède les bonus' => [
            3599, // socle de 89, pas 90
            [self::bonus(ModifierSource::Streak, 20)],
            [[XpBreakdownSource::Base, 89], [XpBreakdownSource::Streak, 17]],
            106,
        ];
    }

    /**
     * @param list<array{XpBreakdownSource, int}> $expectedLines
     */
    #[DataProvider('guardedDays')]
    public function testTheGuardRailsShowWhatTheyTrimmed(DailyLoad $today, int $durationSeconds, array $expectedLines, int $expectedTotal): void
    {
        // Le joueur doit comprendre, pas subir : un total amaigri sans ligne qui l'explique
        // ferait passer une mécanique de jeu pour une punition arbitraire.
        $award = self::calculator()->calculate(Discipline::Running, $durationSeconds, [], $today);

        self::assertSame($expectedLines, self::linesOf($award->breakdown->lines));
        self::assertSame($expectedTotal, $award->amount());
    }

    /**
     * @return iterable<string, array{DailyLoad, int, list<array{XpBreakdownSource, int}>, int}>
     */
    public static function guardedDays(): iterable
    {
        // Dans la première tranche, rien n'est rogné : pas de ligne inutile.
        yield 'journée vierge, aucune ligne de garde-fou' => [
            DailyLoad::untouched(),
            2700,
            [[XpBreakdownSource::Base, 67]],
            67,
        ];

        // 50 min déjà faites, séance de 20 : 10 min à 100 %, 10 min à 60 % = 16 min
        // retenues. Socle plein 30, socle retenu 24.
        yield 'à cheval sur deux tranches' => [
            new DailyLoad(50 * 60, 0),
            20 * 60,
            [[XpBreakdownSource::Base, 30], [XpBreakdownSource::Diminishing, -6]],
            24,
        ];

        yield 'au-delà des tranches, la séance ne rapporte plus rien' => [
            new DailyLoad(130 * 60, 0),
            30 * 60,
            [[XpBreakdownSource::Base, 45], [XpBreakdownSource::Diminishing, -45]],
            0,
        ];

        // 150 déjà gagnés sur un plafond de 180 : il reste 30 à prendre sur les 45 gagnés.
        yield 'le plafond quotidien écrête' => [
            new DailyLoad(0, 150),
            30 * 60,
            [[XpBreakdownSource::Base, 45], [XpBreakdownSource::DailyCap, -15]],
            30,
        ];

        // Le plafond écrête, il ne rejette pas — même geste qu'au plafond de durée d'une
        // séance. Le joueur au plafond voit tout partir, et la ligne le dit.
        yield 'déjà au plafond' => [
            new DailyLoad(0, 180),
            30 * 60,
            [[XpBreakdownSource::Base, 45], [XpBreakdownSource::DailyCap, -45]],
            0,
        ];

        // Les deux garde-fous se cumulent, chacun avec sa ligne. 80 min déjà faites, séance
        // de 20 min : elle traverse deux tranches — 10 min à 60 % puis 10 min à 30 %, soit
        // 9 min retenues. Socle plein 30, retenu 13. Et il ne reste que 10 XP sous le
        // plafond, d'où −3 de plus.
        yield 'les deux garde-fous à la fois' => [
            new DailyLoad(80 * 60, 170),
            20 * 60,
            [
                [XpBreakdownSource::Base, 30],
                [XpBreakdownSource::Diminishing, -17],
                [XpBreakdownSource::DailyCap, -3],
            ],
            10,
        ];
    }

    public function testTheBonusesApplyToTheTrimmedBase(): void
    {
        // 50 min déjà faites : socle plein 30, retenu 24. Le streak porte sur 24, pas sur
        // 30 — sinon un bonus rendrait le rendement décroissant contournable.
        $award = self::calculator()->calculate(Discipline::Running, 20 * 60, [
            self::bonus(ModifierSource::Streak, 50),
        ], new DailyLoad(50 * 60, 0));

        self::assertSame(
            [
                [XpBreakdownSource::Base, 30],
                [XpBreakdownSource::Diminishing, -6],
                [XpBreakdownSource::Streak, 12],
            ],
            self::linesOf($award->breakdown->lines),
        );
        self::assertSame(36, $award->amount());
    }

    public function testTheDailyCapCountsWhatWasAlreadyEarnedInThatDisciplineOnly(): void
    {
        // Le plafond est par discipline : avoir saturé la course n'entame pas la natation.
        $award = self::calculator()->calculate(Discipline::Swimming, 30 * 60, [], new DailyLoad(0, 0));

        self::assertSame([[XpBreakdownSource::Base, 50]], self::linesOf($award->breakdown->lines));
    }

    public function testIgnoresAModifierScopedToAnotherDiscipline(): void
    {
        // Des bottes de course ne servent à rien en natation, et le calcul n'a pas eu à
        // connaître les objets pour le savoir.
        $award = self::calculator()->calculate(Discipline::Swimming, 3600, [
            new Modifier(ModifierType::XpMultiplier, 20, ModifierSource::Item, Discipline::Running),
        ], DailyLoad::untouched());

        self::assertSame([[XpBreakdownSource::Base, 100]], self::linesOf($award->breakdown->lines));
    }

    public function testAppliesAModifierScopedToTheSessionDiscipline(): void
    {
        $award = self::calculator()->calculate(Discipline::Running, 3600, [
            new Modifier(ModifierType::XpMultiplier, 20, ModifierSource::Item, Discipline::Running),
        ], DailyLoad::untouched());

        self::assertSame(108, $award->amount());
    }

    public function testIgnoresAModifierOfAnotherType(): void
    {
        // Le vocabulaire est unique, donc le resolver rendra un ensemble mêlé : c'est au
        // consommateur de ne prendre que ce qui le concerne.
        $award = self::calculator()->calculate(Discipline::Running, 3600, [
            new Modifier(ModifierType::LootLuck, 50, ModifierSource::Item),
            new Modifier(ModifierType::StreakShield, 1, ModifierSource::Streak),
        ], DailyLoad::untouched());

        self::assertSame([[XpBreakdownSource::Base, 90]], self::linesOf($award->breakdown->lines));
    }

    public function testTheBreakdownDoesNotDependOnTheOrderOfTheModifiers(): void
    {
        // Sans ça, deux calculs identiques écriraient deux lignes de ledger différentes.
        $shuffled = self::calculator()->calculate(Discipline::Running, 3600, [
            self::bonus(ModifierSource::League, 10),
            self::bonus(ModifierSource::Item, 15),
            self::bonus(ModifierSource::Streak, 20),
        ], DailyLoad::untouched());

        $ordered = self::calculator()->calculate(Discipline::Running, 3600, [
            self::bonus(ModifierSource::Streak, 20),
            self::bonus(ModifierSource::Item, 15),
            self::bonus(ModifierSource::League, 10),
        ], DailyLoad::untouched());

        self::assertSame(self::linesOf($ordered->breakdown->lines), self::linesOf($shuffled->breakdown->lines));
        self::assertSame(
            [
                [XpBreakdownSource::Base, 90],
                [XpBreakdownSource::Streak, 18],
                [XpBreakdownSource::Item, 13],
                [XpBreakdownSource::League, 9],
            ],
            self::linesOf($ordered->breakdown->lines),
        );
    }

    public function testCarriesTheRulesetVersionItWasCalculatedUnder(): void
    {
        // Le montant et la version voyagent ensemble : une écriture au ledger ne peut pas
        // être datée des règles d'un autre calcul.
        self::assertSame('v1-abcdef012345', self::calculator()->calculate(Discipline::Running, 3600, [], DailyLoad::untouched())->rulesetVersion);
    }

    private static function calculator(): XpCalculator
    {
        return new XpCalculator(
            new XpRates([
                ['discipline' => 'RUNNING', 'xp_per_hour' => 90, 'daily_cap_xp' => 180],
                ['discipline' => 'CYCLING', 'xp_per_hour' => 70, 'daily_cap_xp' => 140],
                ['discipline' => 'SWIMMING', 'xp_per_hour' => 100, 'daily_cap_xp' => 200],
                ['discipline' => 'STRENGTH', 'xp_per_hour' => 80, 'daily_cap_xp' => 160],
                ['discipline' => 'MOBILITY', 'xp_per_hour' => 50, 'daily_cap_xp' => 100],
                ['discipline' => 'CLIMBING', 'xp_per_hour' => 85, 'daily_cap_xp' => 170],
            ]),
            new DiminishingReturns([
                ['up_to_minutes' => 60, 'weight_percent' => 100],
                ['up_to_minutes' => 90, 'weight_percent' => 60],
                ['up_to_minutes' => 120, 'weight_percent' => 30],
            ], 0),
            'v1-abcdef012345',
        );
    }

    private static function bonus(ModifierSource $source, int $percentage): Modifier
    {
        return new Modifier(ModifierType::XpMultiplier, $percentage, $source);
    }

    /**
     * @param list<XpBreakdownLine> $lines
     *
     * @return list<array{XpBreakdownSource, int}>
     */
    private static function linesOf(array $lines): array
    {
        return array_map(static fn (XpBreakdownLine $line): array => [$line->source, $line->amount], $lines);
    }
}
