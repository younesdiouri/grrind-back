<?php

declare(strict_types=1);

namespace App\Tests\Training\Domain;

use App\Training\Domain\WorkoutRules;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'équilibrage est du config-as-code : il se modifie sans revue de code, donc il doit se
 * refuser tout seul quand il n'a pas de sens. Une configuration incohérente tombe au
 * démarrage, pas le jour où un joueur croise le cas.
 *
 * Les trois arbitrages eux-mêmes sont ici parce qu'ils sont **purs** : ils ne lisent ni la
 * base ni l'horloge, et se testent par table de cas.
 */
final class WorkoutRulesTest extends TestCase
{
    public function testAcceptsTheShippedBalance(): void
    {
        $rules = self::rules();

        self::assertSame(300, $rules->minimumDurationSeconds);
        self::assertSame(14400, $rules->maximumDurationSeconds);
        self::assertSame(30, $rules->importWindowDays);
    }

    /**
     * Le plancher écarte, il n'écrête pas : douze secondes n'est pas une séance courte,
     * c'est un faux départ sur la montre.
     */
    #[DataProvider('durations')]
    public function testTellsAFalseStartFromAShortSession(int $durationSeconds, bool $tooShort): void
    {
        self::assertSame($tooShort, self::rules()->isTooShort($durationSeconds));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function durations(): iterable
    {
        yield 'douze secondes' => [12, true];
        yield 'juste sous le plancher' => [299, true];
        yield 'le plancher exactement' => [300, false];
        yield 'une demi-heure' => [1800, false];
    }

    /**
     * Le plafond, lui, écrête et ne rejette jamais : l'enregistrement oublié sur la montre
     * rend quatre heures créditées au lieu de tout perdre.
     */
    #[DataProvider('clampedDurations')]
    public function testClampsRatherThanRejects(int $durationSeconds, int $retained): void
    {
        self::assertSame($retained, self::rules()->retainedDuration($durationSeconds));
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function clampedDurations(): iterable
    {
        yield 'une demi-heure passe intacte' => [1800, 1800];
        yield 'le plafond exactement' => [14400, 14400];
        yield 'la montre oubliée toute la nuit' => [12 * 3600, 14400];
    }

    /**
     * La fenêtre porte sur la **fin** et non sur le début : une randonnée de deux jours n'a
     * pas à se faire refuser pour son départ.
     */
    #[DataProvider('windowCases')]
    public function testDecidesWhatIsRecentEnoughToBeCredited(string $endedAt, bool $within): void
    {
        $now = new DateTimeImmutable('2026-08-13T12:00:00+00:00');

        self::assertSame($within, self::rules()->isWithinWindow(new DateTimeImmutable($endedAt), $now));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function windowCases(): iterable
    {
        yield 'ce matin' => ['2026-08-13T07:00:00+00:00', true];
        yield 'la semaine dernière' => ['2026-08-06T07:00:00+00:00', true];
        yield 'la veille de la borne' => ['2026-07-14T12:00:01+00:00', true];
        yield 'la borne exactement' => ['2026-07-14T12:00:00+00:00', true];
        yield 'un jour de trop' => ['2026-07-14T11:59:59+00:00', false];
        yield 'les archives de trois ans' => ['2023-08-13T07:00:00+00:00', false];
    }

    #[DataProvider('nonsense')]
    public function testRefusesAnIncoherentBalance(int $minimum, int $maximum, int $windowDays): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkoutRules($minimum, $maximum, $windowDays);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function nonsense(): iterable
    {
        // Aucun workout ne pourrait être retenu : refusé sous le plancher, écrêté sous ce
        // même plancher au-dessus.
        yield 'plafond sous le plancher' => [3600, 60, 30];
        yield 'plancher négatif' => [-1, 14400, 30];

        // Une fenêtre nulle ne créditerait plus rien du tout : la séance du matin est déjà
        // passée quand elle arrive.
        yield 'fenêtre nulle' => [300, 14400, 0];
    }

    private static function rules(): WorkoutRules
    {
        return new WorkoutRules(300, 14400, 30);
    }
}
