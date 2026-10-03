<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Domain;

use App\Engagement\Domain\StreakRules;
use App\Shared\Application\StreakState;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La fenêtre glissante stricte du #286, jour par jour. Un calendrier s'écrit `S` pour un
 * jour sportif et `.` pour un jour sans ; il commence le 2026-01-01 et son dernier
 * caractère est le jour courant.
 */
final class StreakRulesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string, int, int, int, string}>
     */
    public static function calendars(): iterable
    {
        yield 'aucun sport' => ['....', null, 0, 0, 0, 'COMMON'];
        yield 'une première séance ouvre la série' => ['..S', '2026-01-03', 1, 0, 1, 'COMMON'];
        yield 'le jour courant sans séance ne casse rien' => ['S.SS.', '2026-01-01', 5, 0, 3, 'COMMON'];
        yield '6 sur 7 : premier coffre, commun' => ['SSS.SSS', '2026-01-01', 7, 1, 6, 'RARE'];
        yield 'le 6e jour sportif suffit, la semaine n’a pas à finir' => ['SSSSSS', '2026-01-01', 6, 1, 6, 'RARE'];
        yield '5 sur 7 : pas de coffre' => ['SS.SSS.', '2026-01-01', 7, 0, 5, 'COMMON'];
        yield 'deux manqués à 6 jours d’écart : rupture au second' => ['S.SSSSS.S', '2026-01-09', 1, 0, 6, 'COMMON'];
        yield 'deux manqués à 7 jours d’écart : la série tient' => ['S.SSSSSS.S', '2026-01-01', 10, 1, 6, 'RARE'];
        yield 'on repart de zéro au jour sportif suivant' => ['SS..SSS', '2026-01-05', 3, 0, 5, 'COMMON'];
        yield 'deux semaines : rare ensuite épique' => ['SSSSSS.SSSSSS.', '2026-01-01', 14, 2, 6, 'EPIC'];
        yield 'quatre semaines : le légendaire' => [str_repeat('SSSSSS.', 4), '2026-01-01', 28, 4, 6, 'LEGENDARY'];
        yield 'puis un légendaire chaque semaine' => [str_repeat('SSSSSS.', 6), '2026-01-01', 42, 6, 6, 'LEGENDARY'];
        yield 'un manqué en fin de semaine et un en début de suivante cassent' => ['SSSSSS..S', '2026-01-09', 1, 0, 5, 'COMMON'];
    }

    #[DataProvider('calendars')]
    public function testItEvaluatesTheStrictSlidingWindow(string $calendar, ?string $startedOn, int $days, int $weeks, int $last7, string $next): void
    {
        self::assertEquals(new StreakState($startedOn, $days, $weeks, $last7, $next), self::evaluate($calendar));
    }

    public function testDaysAfterTodayAreIgnored(): void
    {
        self::assertEquals(
            self::evaluate('SSS'),
            StreakRules::evaluate(['2026-01-01' => true, '2026-01-02' => true, '2026-01-03' => true, '2026-01-04' => true], '2026-01-03'),
        );
    }

    public function testTheRarityOfEachWeek(): void
    {
        self::assertSame(['COMMON', 'RARE', 'EPIC', 'LEGENDARY', 'LEGENDARY'], array_map(StreakRules::rarityOf(...), [1, 2, 3, 4, 5]));
    }

    private static function evaluate(string $calendar): StreakState
    {
        $first = new DateTimeImmutable('2026-01-01');
        $days = [];
        foreach (str_split($calendar) as $offset => $mark) {
            if ('S' === $mark) {
                $days[$first->modify("+{$offset} days")->format('Y-m-d')] = true;
            }
        }

        return StreakRules::evaluate($days, $first->modify('+'.(\strlen($calendar) - 1).' days')->format('Y-m-d'));
    }
}
