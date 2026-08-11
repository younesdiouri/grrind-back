<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\DiminishingReturns;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les tranches livrées : 0-60 min ×1, 60-90 ×0,6, 90-120 ×0,3, au-delà ×0.
 *
 * Ce qui se démontre ici est le découpage **par tranche** : une séance à cheval en compte
 * une part dans chacune. Un palier global aurait produit une falaise où la 61ᵉ minute fait
 * perdre de l'XP déjà acquise, et le joueur apprendrait à s'arrêter à 59.
 */
final class DiminishingReturnsTest extends TestCase
{
    #[DataProvider('sessions')]
    public function testRetainsWhatTheDayStillAllows(int $alreadyTodayMinutes, int $sessionMinutes, int $expectedRetainedMinutes): void
    {
        self::assertSame(
            $expectedRetainedMinutes * 60,
            self::shipped()->retain($alreadyTodayMinutes * 60, $sessionMinutes * 60),
        );
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function sessions(): iterable
    {
        yield 'première séance, dans la première tranche' => [0, 45, 45];
        yield 'la première heure vaut son temps' => [0, 60, 60];

        // Le cas qui donne son nom au ticket : 10 min à 100 %, 10 min à 60 %.
        yield 'à cheval sur deux tranches' => [50, 20, 16];

        // 60 à 100 %, 30 à 60 %, 30 à 30 % = 60 + 18 + 9.
        yield 'la journée entière d\'un coup, jusqu\'au bout des tranches' => [0, 120, 87];

        // Au-delà, plus rien : les minutes 120+ ne pèsent pas.
        yield 'trois heures d\'affilée' => [0, 180, 87];

        yield 'entièrement dans la deuxième tranche' => [60, 30, 18];
        yield 'entièrement dans la troisième' => [90, 30, 9];
        yield 'entièrement au-delà' => [120, 60, 0];

        // Trois tranches traversées par une seule séance : 10 à 100 %, 30 à 60 %,
        // 30 à 30 %, 10 à 0 %.
        yield 'à cheval sur tout' => [50, 80, 37];

        yield 'séance nulle' => [30, 0, 0];
    }

    public function testTheDayIsCumulativeWhicheverWayItIsSliced(): void
    {
        $returns = self::shipped();

        // Trois séances de 40 min, ou une de 120 : le total retenu est le même. Sans ça,
        // découper sa journée deviendrait une stratégie.
        $inOneGo = $returns->retain(0, 120 * 60);
        $inThree = $returns->retain(0, 40 * 60)
            + $returns->retain(40 * 60, 40 * 60)
            + $returns->retain(80 * 60, 40 * 60);

        self::assertSame($inOneGo, $inThree);
    }

    public function testRefusesANegativeDay(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::shipped()->retain(-1, 600);
    }

    /**
     * @param list<array{up_to_minutes: int, weight_percent: int}> $brackets
     */
    #[DataProvider('unusableBrackets')]
    public function testRefusesAnUnusableBalance(array $brackets, int $beyond, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($expectedMessage);

        new DiminishingReturns($brackets, $beyond);
    }

    /**
     * @return iterable<string, array{list<array{up_to_minutes: int, weight_percent: int}>, int, string}>
     */
    public static function unusableBrackets(): iterable
    {
        yield 'aucune tranche' => [[], 0, '/au moins une tranche/'];

        yield 'tranches désordonnées' => [
            [['up_to_minutes' => 90, 'weight_percent' => 100], ['up_to_minutes' => 60, 'weight_percent' => 60]],
            0,
            '/strictement croissantes/',
        ];

        // « Décroissants » est le nom de la mécanique : un poids qui remonte rendrait
        // rentable d'attendre avant de reprendre.
        yield 'un rendement qui remonte' => [
            [['up_to_minutes' => 60, 'weight_percent' => 60], ['up_to_minutes' => 90, 'weight_percent' => 100]],
            0,
            '/ne remonte pas/',
        ];

        yield 'le au-delà qui remonte' => [
            [['up_to_minutes' => 60, 'weight_percent' => 100], ['up_to_minutes' => 90, 'weight_percent' => 30]],
            60,
            '/ne remonte pas/',
        ];

        // Au-dessus de 100 ce serait une prime au volume, l'inverse exact du garde-fou.
        yield 'un poids au-dessus de 100' => [
            [['up_to_minutes' => 60, 'weight_percent' => 150]],
            0,
            '/entre 0 et 100/',
        ];
    }

    /** Les tranches réellement livrées dans `config/game/v1/xp.yaml`. */
    private static function shipped(): DiminishingReturns
    {
        return new DiminishingReturns([
            ['up_to_minutes' => 60, 'weight_percent' => 100],
            ['up_to_minutes' => 90, 'weight_percent' => 60],
            ['up_to_minutes' => 120, 'weight_percent' => 30],
        ], 0);
    }
}
