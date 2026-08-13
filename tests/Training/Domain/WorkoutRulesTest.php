<?php

declare(strict_types=1);

namespace App\Tests\Training\Domain;

use App\Training\Domain\WorkoutRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'équilibrage est du config-as-code : il se modifie sans revue de code, donc il doit
 * se refuser tout seul quand il n'a pas de sens. Une configuration incohérente tombe au
 * démarrage, pas le jour où un joueur croise le cas.
 */
final class WorkoutRulesTest extends TestCase
{
    public function testAcceptsTheShippedBalance(): void
    {
        $rules = new WorkoutRules(300, 14400);

        self::assertSame(300, $rules->minimumDurationSeconds);
        self::assertSame(14400, $rules->maximumDurationSeconds);
    }

    #[DataProvider('nonsense')]
    public function testRefusesAnIncoherentBalance(int $minimum, int $maximum): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkoutRules($minimum, $maximum);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function nonsense(): iterable
    {
        // Aucun workout ne pourrait être retenu : refusé sous le plancher, écrêté sous ce
        // même plancher au-dessus.
        yield 'plafond sous le plancher' => [3600, 60];
        yield 'plancher négatif' => [-1, 14400];
    }
}
