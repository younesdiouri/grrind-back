<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Infrastructure\GameRulesetSeed as RuntimeSeed;
use App\Admin\Domain\GameRuleset;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\ORM\EntityManagerInterface;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Une migration doit pouvoir recréer le catalogue sans dépendre du code applicatif mutable. */
final class GameRulesetMigrationSeedTest extends KernelTestCase
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
        self::assertCount(12, $migrationSeed['disciplines']);
        self::assertCount(50, $migrationSeed['levels']);
        self::assertCount(63, $migrationSeed['activity_types']);
        self::assertArrayHasKey('training', $migrationSeed);
        self::assertArrayHasKey('xp', $migrationSeed);
        self::assertArrayHasKey('attributes', $migrationSeed);
        self::assertArrayHasKey('community', $migrationSeed);
        self::assertArrayHasKey('notifications', $migrationSeed);
    }

    public function testMigrationPublishesTheEntireFrozenSeedWithItsCanonicalVersion(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $ruleset = $manager->find(GameRuleset::class, 1);
        self::assertInstanceOf(GameRuleset::class, $ruleset);
        $snapshot = $ruleset->snapshot();

        foreach (['training', 'xp', 'attributes', 'disciplines', 'levels', 'activity_types', 'community', 'notifications'] as $section) {
            self::assertArrayHasKey($section, $snapshot);
        }
        self::assertCount(12, $snapshot['disciplines']);
        self::assertCount(50, $snapshot['levels']);
        self::assertCount(63, $snapshot['activity_types']);
        self::assertSame(GameRulesetVersion::of($snapshot), $ruleset->version());
    }

    public function testRetiredCatalogYamlFilesCannotBecomeARuntimeSourceAgain(): void
    {
        $directory = \dirname(__DIR__, 2).'/config/game/v1';

        foreach (['items.yaml', 'loot.yaml', 'titles.yaml', 'combat.yaml'] as $file) {
            self::assertFileDoesNotExist($directory.'/'.$file);
        }
    }
}
