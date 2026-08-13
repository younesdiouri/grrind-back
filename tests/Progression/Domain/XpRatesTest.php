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
        self::assertSame($expected, self::rates()->baseFor($durationSeconds));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function prorataCases(): iterable
    {
        // Une heure vaut 90 XP dans ce barème de test — pas les 60 livrés, pour que la
        // troncature se voie sur des chiffres qui ne tombent pas juste.
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

        // Le plancher de durée rend ce cas inatteignable par un import, mais le calcul ne
        // s'appuie pas là-dessus : il vaut pour lui-même.
        yield 'durée nulle' => [0, 0];
    }

    /**
     * **Le socle ne dépend plus de la discipline** (#90) : une minute vaut une minute, que
     * ce soit en course ou en mobilité. Ce qui les distingue est mesuré — la distance, le
     * dénivelé — ou assumé comme absent.
     */
    public function testTheBaseIsTheSameForEveryDiscipline(): void
    {
        $rates = self::rates();

        self::assertSame(45, $rates->baseFor(1800));
        self::assertSame(90, $rates->baseXpPerHour());
    }

    /**
     * Le plafond quotidien, lui, **reste par discipline** : c'est le filet, et il n'a pas
     * de raison d'être uniforme — une randonnée va légitimement plus loin qu'une séance de
     * mobilité.
     */
    public function testTheDailyCapStaysPerDiscipline(): void
    {
        $rates = self::rates();

        self::assertSame(180, $rates->dailyCapOf(Discipline::Running));
        self::assertSame(100, $rates->dailyCapOf(Discipline::Mobility));
    }

    #[DataProvider('distanceCases')]
    public function testConvertsMetresIntoPoints(Discipline $discipline, ?int $distanceMeters, int $expected): void
    {
        self::assertSame($expected, self::rates()->distanceBonusOf($discipline, $distanceMeters));
    }

    /**
     * @return iterable<string, array{Discipline, ?int, int}>
     */
    public static function distanceCases(): iterable
    {
        yield 'dix kilomètres de course' => [Discipline::Running, 10_000, 100];
        yield 'six kilomètres deux' => [Discipline::Running, 6_200, 62];

        // Tronqué vers le bas, comme le socle.
        yield 'quatre-vingts mètres, sous le point' => [Discipline::Running, 80, 0];

        // Une discipline sans taux de distance n'en reçoit pas, même si la montre a mesuré :
        // le barème décide, pas l'appareil.
        yield 'la fonte ne compte pas les mètres' => [Discipline::Strength, 4_000, 0];

        // « Non mesuré » n'est pas « mesuré, et nul », mais les deux ne rapportent rien —
        // c'est l'appelant qui ne produit pas de ligne, et il n'a qu'un cas à traiter.
        yield 'non mesuré' => [Discipline::Running, null, 0];
        yield 'mesuré à zéro' => [Discipline::Running, 0, 0];
    }

    /**
     * Le dénivelé n'est déclaré que sur la randonnée, où il *est* l'effort. Une course en
     * côte le gagnera peut-être ; ce sera une ligne de YAML, et rien d'autre.
     */
    #[DataProvider('elevationCases')]
    public function testConvertsElevationIntoPoints(Discipline $discipline, ?int $elevationGainMeters, int $expected): void
    {
        self::assertSame($expected, self::rates()->elevationBonusOf($discipline, $elevationGainMeters));
    }

    /**
     * @return iterable<string, array{Discipline, ?int, int}>
     */
    public static function elevationCases(): iterable
    {
        yield 'six cent quarante mètres de D+' => [Discipline::Hiking, 640, 128];
        yield 'quatre-vingt-dix mètres, sous la tranche' => [Discipline::Hiking, 90, 18];
        yield 'la course n\'a pas de taux de dénivelé' => [Discipline::Running, 600, 0];
        yield 'un parcours plat' => [Discipline::Hiking, 0, 0];
        yield 'non mesuré' => [Discipline::Hiking, null, 0];
    }

    public function testRefusesANegativeDuration(): void
    {
        // Ce n'est pas une séance courte, c'est un bug d'appelant : mieux vaut lever que
        // créditer un montant négatif sous couvert de socle.
        $this->expectException(InvalidArgumentException::class);

        self::rates()->baseFor(-1);
    }

    /**
     * @param list<array{discipline: string, daily_cap_xp: int, xp_per_km?: int, xp_per_100m_elevation?: int}> $disciplines
     */
    #[DataProvider('unusableRates')]
    public function testRefusesAnUnusableBalance(int $baseXpPerHour, array $disciplines, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        new XpRates($baseXpPerHour, $disciplines);
    }

    /**
     * @return iterable<string, array{int, list<array{discipline: string, daily_cap_xp: int, xp_per_km?: int, xp_per_100m_elevation?: int}>, string}>
     */
    public static function unusableRates(): iterable
    {
        // Le cas qui compte : sans ce refus, la discipline oubliée rapporterait zéro en
        // silence, et c'est un joueur qui découvrirait le trou.
        yield 'discipline non couverte' => [
            90,
            [['discipline' => 'RUNNING', 'daily_cap_xp' => 180]],
            '/Aucun barème/',
        ];

        yield 'discipline inconnue' => [
            90,
            [...self::everyDiscipline(), ['discipline' => 'QUIDDITCH', 'daily_cap_xp' => 180]],
            '/inconnue/',
        ];

        yield 'discipline en double' => [
            90,
            [...self::everyDiscipline(), ['discipline' => 'RUNNING', 'daily_cap_xp' => 240]],
            '/double/',
        ];

        yield 'socle nul' => [
            0,
            self::everyDiscipline(),
            '/au moins 1 XP/',
        ];

        // Un plafond sous ce qu'une heure de socle rapporte ferait du garde-fou le limiteur
        // principal, à la place des rendements décroissants — et le joueur buterait dessus
        // tous les jours sans comprendre pourquoi.
        yield 'plafond sous le socle horaire' => [
            90,
            [...self::everyDisciplineExceptRunning(), ['discipline' => 'RUNNING', 'daily_cap_xp' => 60]],
            '/sous ce qu\'une heure de socle rapporte/',
        ];
    }

    /**
     * Un barème de test, indépendant de celui qui est livré : un rééquilibrage ne doit pas
     * casser une table de cas qui parle d'arithmétique.
     */
    private static function rates(): XpRates
    {
        return new XpRates(90, self::everyDiscipline());
    }

    /**
     * @return list<array{discipline: string, daily_cap_xp: int, xp_per_km?: int, xp_per_100m_elevation?: int}>
     */
    private static function everyDiscipline(): array
    {
        return [
            ['discipline' => 'RUNNING', 'daily_cap_xp' => 180, 'xp_per_km' => 10],
            ...self::everyDisciplineExceptRunning(),
        ];
    }

    /**
     * @return list<array{discipline: string, daily_cap_xp: int, xp_per_km?: int, xp_per_100m_elevation?: int}>
     */
    private static function everyDisciplineExceptRunning(): array
    {
        return [
            ['discipline' => 'WALKING', 'daily_cap_xp' => 180, 'xp_per_km' => 5],
            ['discipline' => 'CYCLING', 'daily_cap_xp' => 180, 'xp_per_km' => 3],
            ['discipline' => 'SWIMMING', 'daily_cap_xp' => 200, 'xp_per_km' => 50],
            ['discipline' => 'HIKING', 'daily_cap_xp' => 300, 'xp_per_km' => 8, 'xp_per_100m_elevation' => 20],
            ['discipline' => 'STRENGTH', 'daily_cap_xp' => 160],
            ['discipline' => 'HIIT', 'daily_cap_xp' => 220],
            ['discipline' => 'MOBILITY', 'daily_cap_xp' => 100],
            ['discipline' => 'CLIMBING', 'daily_cap_xp' => 170],
        ];
    }
}
