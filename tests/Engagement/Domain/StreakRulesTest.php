<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Domain;

use App\Engagement\Domain\StreakRules;
use PHPUnit\Framework\TestCase;

/** Le streak de l'assiduité (#346), sans infra. */
final class StreakRulesTest extends TestCase
{
    public function testTheStreakCountsConsecutiveCheckInsUpToToday(): void
    {
        $checkIns = ['2026-11-01' => true, '2026-11-03' => true, '2026-11-04' => true, '2026-11-05' => true];

        self::assertSame(3, StreakRules::days($checkIns, '2026-11-05'));
        // Aujourd'hui pas encore pointé : la série tient jusqu'à hier.
        self::assertSame(3, StreakRules::days($checkIns, '2026-11-06'));
        // Hier manqué : la série est cassée.
        self::assertSame(0, StreakRules::days($checkIns, '2026-11-07'));
        self::assertSame(0, StreakRules::days([], '2026-11-07'));
    }

    public function testSportDaysChooseTheTier(): void
    {
        self::assertSame(
            ['COMMON', 'COMMON', 'COMMON', 'RARE', 'RARE', 'RARE', 'LEGENDARY', 'LEGENDARY'],
            array_map(StreakRules::rarityFor(...), range(0, 7)),
        );
    }

    public function testAWeekStartsOnMonday(): void
    {
        self::assertSame('2026-11-02', StreakRules::weekStart('2026-11-02'));
        self::assertSame('2026-11-02', StreakRules::weekStart('2026-11-08'));
    }
}
