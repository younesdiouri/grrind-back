<?php

declare(strict_types=1);

namespace App\Tests\Training\Domain;

use App\Training\Domain\TrainingRules;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'équilibrage est du config-as-code : il se modifie sans revue de code, donc il doit
 * se refuser tout seul quand il n'a pas de sens. Une configuration incohérente tombe au
 * démarrage, pas le jour où un joueur croise le cas.
 */
final class TrainingRulesTest extends TestCase
{
    public function testAcceptsTheShippedBalance(): void
    {
        $rules = new TrainingRules(300, 14400, 900);

        self::assertSame(300, $rules->minimumDurationSeconds);
        self::assertSame(14400, $rules->maximumDurationSeconds);
        self::assertSame(900, $rules->cooldownSeconds);
    }

    #[DataProvider('nonsense')]
    public function testRefusesAnIncoherentBalance(int $minimum, int $maximum, int $cooldown): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TrainingRules($minimum, $maximum, $cooldown);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function nonsense(): iterable
    {
        // Aucune séance ne pourrait être close : trop courte sous le plancher, écrêtée
        // sous ce même plancher au-dessus.
        yield 'plafond sous le plancher' => [3600, 60, 900];
        yield 'plancher négatif' => [-1, 14400, 900];
        yield 'cooldown négatif' => [300, 14400, -1];
    }
}
