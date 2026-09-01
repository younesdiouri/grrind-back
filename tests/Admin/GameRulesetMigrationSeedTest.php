<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Infrastructure\GameRulesetSeed as RuntimeSeed;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** Une migration doit pouvoir recréer le catalogue sans dépendre du code applicatif mutable. */
final class GameRulesetMigrationSeedTest extends TestCase
{
    public function testMigrationOwnsTheExactDeterministicInitialSnapshot(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/GameRulesetSeed.php';
        $migrationSeed = new ReflectionMethod('DoctrineMigrations\\GameRulesetSeed', 'data')->invoke(null);
        self::assertIsArray($migrationSeed);
        /** @var array{items: list<mixed>, titles: list<mixed>, enemies: list<mixed>, loot: array{adversary: list<mixed>}} $migrationSeed */
        self::assertSame(RuntimeSeed::data(), $migrationSeed);
        self::assertCount(12, $migrationSeed['items']);
        self::assertCount(17, $migrationSeed['titles']);
        self::assertCount(6, $migrationSeed['enemies']);
        self::assertCount(10, $migrationSeed['loot']['adversary']);
    }
}
