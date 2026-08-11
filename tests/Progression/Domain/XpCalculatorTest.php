<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

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
        $award = self::calculator()->calculate(Discipline::Running, $durationSeconds, $modifiers);

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

    public function testIgnoresAModifierScopedToAnotherDiscipline(): void
    {
        // Des bottes de course ne servent à rien en natation, et le calcul n'a pas eu à
        // connaître les objets pour le savoir.
        $award = self::calculator()->calculate(Discipline::Swimming, 3600, [
            new Modifier(ModifierType::XpMultiplier, 20, ModifierSource::Item, Discipline::Running),
        ]);

        self::assertSame([[XpBreakdownSource::Base, 100]], self::linesOf($award->breakdown->lines));
    }

    public function testAppliesAModifierScopedToTheSessionDiscipline(): void
    {
        $award = self::calculator()->calculate(Discipline::Running, 3600, [
            new Modifier(ModifierType::XpMultiplier, 20, ModifierSource::Item, Discipline::Running),
        ]);

        self::assertSame(108, $award->amount());
    }

    public function testIgnoresAModifierOfAnotherType(): void
    {
        // Le vocabulaire est unique, donc le resolver rendra un ensemble mêlé : c'est au
        // consommateur de ne prendre que ce qui le concerne.
        $award = self::calculator()->calculate(Discipline::Running, 3600, [
            new Modifier(ModifierType::LootLuck, 50, ModifierSource::Item),
            new Modifier(ModifierType::StreakShield, 1, ModifierSource::Streak),
        ]);

        self::assertSame([[XpBreakdownSource::Base, 90]], self::linesOf($award->breakdown->lines));
    }

    public function testTheBreakdownDoesNotDependOnTheOrderOfTheModifiers(): void
    {
        // Sans ça, deux calculs identiques écriraient deux lignes de ledger différentes.
        $shuffled = self::calculator()->calculate(Discipline::Running, 3600, [
            self::bonus(ModifierSource::League, 10),
            self::bonus(ModifierSource::Item, 15),
            self::bonus(ModifierSource::Streak, 20),
        ]);

        $ordered = self::calculator()->calculate(Discipline::Running, 3600, [
            self::bonus(ModifierSource::Streak, 20),
            self::bonus(ModifierSource::Item, 15),
            self::bonus(ModifierSource::League, 10),
        ]);

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
        self::assertSame('v1-abcdef012345', self::calculator()->calculate(Discipline::Running, 3600, [])->rulesetVersion);
    }

    private static function calculator(): XpCalculator
    {
        return new XpCalculator(
            new XpRates([
                ['discipline' => 'RUNNING', 'xp_per_hour' => 90],
                ['discipline' => 'CYCLING', 'xp_per_hour' => 70],
                ['discipline' => 'SWIMMING', 'xp_per_hour' => 100],
                ['discipline' => 'STRENGTH', 'xp_per_hour' => 80],
                ['discipline' => 'MOBILITY', 'xp_per_hour' => 50],
                ['discipline' => 'CLIMBING', 'xp_per_hour' => 85],
            ]),
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
