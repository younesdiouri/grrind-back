<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\XpRates;
use App\Shared\Domain\Activity\Discipline;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le barème est de l'équilibrage : il se modifie sans revue de code, donc il doit se
 * refuser tout seul quand il n'a pas de sens. Et son arithmétique est entière de bout en
 * bout — c'est ce qui garantit qu'aucun arrondi flottant n'atteint le ledger.
 */
final class XpRatesTest extends TestCase
{
    #[DataProvider('prorataCases')]
    public function testProratesTheHourlyRate(int $durationSeconds, int $expected): void
    {
        self::assertSame($expected, self::rates()->baseFor(Discipline::Running, $durationSeconds));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function prorataCases(): iterable
    {
        // Une heure de course vaut 90 XP : le barème se lit comme une phrase.
        yield 'une heure pile' => [3600, 90];
        yield 'deux heures' => [7200, 180];
        yield 'une demi-heure' => [1800, 45];
        yield 'trente-cinq minutes' => [2100, 52];

        // Tronqué vers le bas, jamais arrondi au plus proche : une séance ne peut pas
        // rapporter plus que ce que le barème annonce.
        yield 'la seconde qui manque' => [3599, 89];
        yield 'une minute' => [60, 1];
        yield 'quarante secondes, sous le point' => [40, 1];
        yield 'trente-neuf secondes' => [39, 0];

        // Le plancher de durée (5 min) rend ce cas inatteignable par une clôture, mais le
        // calcul ne s'appuie pas là-dessus : il vaut pour lui-même.
        yield 'durée nulle' => [0, 0];
    }

    public function testEachDisciplineHasItsOwnRate(): void
    {
        $rates = self::rates();

        self::assertSame(90, $rates->perHourOf(Discipline::Running));
        self::assertSame(50, $rates->perHourOf(Discipline::Mobility));
        self::assertSame(25, $rates->baseFor(Discipline::Mobility, 1800));
    }

    public function testRefusesANegativeDuration(): void
    {
        // Ce n'est pas une séance courte, c'est un bug d'appelant : mieux vaut lever que
        // créditer un montant négatif sous couvert de socle.
        $this->expectException(InvalidArgumentException::class);

        self::rates()->baseFor(Discipline::Running, -1);
    }

    /**
     * @param list<array{discipline: string, xp_per_hour: int}> $disciplines
     */
    #[DataProvider('unusableRates')]
    public function testRefusesAnUnusableBalance(array $disciplines, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        new XpRates($disciplines);
    }

    /**
     * @return iterable<string, array{list<array{discipline: string, xp_per_hour: int}>, string}>
     */
    public static function unusableRates(): iterable
    {
        // Le cas qui compte : sans ce refus, la discipline oubliée rapporterait zéro en
        // silence, et c'est un joueur qui découvrirait le trou.
        yield 'discipline non couverte' => [
            [['discipline' => 'RUNNING', 'xp_per_hour' => 90]],
            '/Aucun barème.*CYCLING|Aucun barème/',
        ];

        yield 'discipline inconnue' => [
            [...self::everyDiscipline(), ['discipline' => 'QUIDDITCH', 'xp_per_hour' => 90]],
            '/inconnue/',
        ];

        yield 'discipline en double' => [
            [...self::everyDiscipline(), ['discipline' => 'RUNNING', 'xp_per_hour' => 120]],
            '/double/',
        ];

        yield 'taux nul' => [
            [...self::everyDisciplineExceptRunning(), ['discipline' => 'RUNNING', 'xp_per_hour' => 0]],
            '/au moins 1 XP/',
        ];
    }

    /**
     * Un barème de test, indépendant de celui qui est livré : un rééquilibrage ne doit pas
     * casser une table de cas qui parle d'arithmétique.
     */
    private static function rates(): XpRates
    {
        return new XpRates(self::everyDiscipline());
    }

    /**
     * @return list<array{discipline: string, xp_per_hour: int}>
     */
    private static function everyDiscipline(): array
    {
        return [
            ['discipline' => 'RUNNING', 'xp_per_hour' => 90],
            ...self::everyDisciplineExceptRunning(),
        ];
    }

    /**
     * @return list<array{discipline: string, xp_per_hour: int}>
     */
    private static function everyDisciplineExceptRunning(): array
    {
        return [
            ['discipline' => 'CYCLING', 'xp_per_hour' => 70],
            ['discipline' => 'SWIMMING', 'xp_per_hour' => 100],
            ['discipline' => 'STRENGTH', 'xp_per_hour' => 80],
            ['discipline' => 'MOBILITY', 'xp_per_hour' => 50],
            ['discipline' => 'CLIMBING', 'xp_per_hour' => 85],
        ];
    }
}
