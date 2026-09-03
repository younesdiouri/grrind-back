<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameRuleset;
use App\Admin\Infrastructure\GameRulesetSeed as RuntimeSeed;
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
        /** @var array<string, mixed> $migrationSeed */
        self::assertSame(RuntimeSeed::data(), $migrationSeed);
        foreach (['items' => 12, 'titles' => 17, 'enemies' => 6] as $section => $count) {
            self::assertArrayHasKey($section, $migrationSeed);
            self::assertIsArray($migrationSeed[$section]);
            self::assertCount($count, $migrationSeed[$section]);
        }
        self::assertArrayHasKey('loot', $migrationSeed);
        self::assertIsArray($migrationSeed['loot']);
        self::assertArrayHasKey('adversary', $migrationSeed['loot']);
        self::assertIsArray($migrationSeed['loot']['adversary']);
        self::assertCount(10, $migrationSeed['loot']['adversary']);
        foreach (['disciplines' => 12, 'levels' => 50, 'activity_types' => 63] as $section => $count) {
            self::assertArrayHasKey($section, $migrationSeed);
            self::assertIsArray($migrationSeed[$section]);
            self::assertCount($count, $migrationSeed[$section]);
        }
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
        foreach (['disciplines' => 12, 'levels' => 50, 'activity_types' => 63] as $section => $count) {
            self::assertArrayHasKey($section, $snapshot);
            self::assertIsArray($snapshot[$section]);
            self::assertCount($count, $snapshot[$section]);
        }
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
