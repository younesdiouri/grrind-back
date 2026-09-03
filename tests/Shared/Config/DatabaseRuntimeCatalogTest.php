<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Admin\Infrastructure\GameRulesetSeed;
use App\Combat\Domain\EnemyCatalog;
use App\Community\Domain\GuildRules;
use App\Community\Domain\QuietHours;
use App\Community\Domain\RisalaRules;
use App\Progression\Domain\DiminishingReturns;
use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\TitleCatalog;
use App\Progression\Domain\XpRates;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\LootTables;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\ActivityTypeMap;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\Vitality;
use App\Shared\Domain\Timezone;
use App\Shared\Infrastructure\Config\DatabaseGameRulesets;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use App\Training\Domain\WorkoutRules;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Throwable;

/** Les entrées retirées restent lisibles pour les faits, jamais sélectionnables à nouveau. */
final class DatabaseRuntimeCatalogTest extends TestCase
{
    public function testInactiveItemRemainsHistoricallyResolvableButCannotBeBought(): void
    {
        $catalog = ItemCatalog::runtime($this->rulesets());

        self::assertNotNull($catalog->findHistorical('OLD_BOOTS'));
        self::assertNull($catalog->findAvailable('OLD_BOOTS'));
    }

    public function testInactiveEnemyCannotBeChosenButActiveLevelSelectionRemainsAvailable(): void
    {
        $catalog = EnemyCatalog::runtime($this->rulesets());

        self::assertNotNull($catalog->findHistorical('OLD_ENEMY'));
        self::assertNull($catalog->findAvailable('OLD_ENEMY'));
        self::assertSame('ACTIVE_ENEMY', $catalog->forLevel(1)->key);
    }

    public function testInactiveTitleRemainsHistoricalButCannotBeSelectedAgain(): void
    {
        $catalog = TitleCatalog::runtime($this->rulesets());

        self::assertNotNull($catalog->findHistorical('old_title'));
        self::assertNull($catalog->findAvailable('old_title'));
        self::assertNotNull($catalog->findAvailable('active_title'));
    }

    public function testInactiveLootTableIsNeverAvailableForANewRoll(): void
    {
        $rulesets = new class implements GameRulesets {
            public function snapshot(): array
            {
                return [
                    'items' => [],
                    'titles' => [],
                    'combat' => ['fighter' => [], 'enemies' => [['key' => 'OLD_ENEMY']], 'bosses' => []],
                    'loot' => [
                        'version' => 7,
                        'loot_luck' => ['floor_percent' => 0, 'cap_percent' => 100],
                        'workout' => [],
                        'adversary' => [['key' => 'OLD_ENEMY', 'active' => false, 'coins' => ['minimum' => 0, 'maximum' => 0], 'entries' => [['weight' => 1]]]],
                        'chest' => [],
                    ],
                ];
            }

            public function version(): string
            {
                return 'v1-test';
            }

            public function revision(): int
            {
                return 1;
            }
        };

        $tables = LootTables::runtime($rulesets);

        self::assertSame(7, $tables->version());
        self::assertNull($tables->forAdversary('OLD_ENEMY'));
    }

    public function testVersionChangesForGameplayButNotPresentation(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        /** @var array{items: list<array<string, mixed>>} $snapshot */
        $version = GameRulesetVersion::of($snapshot);
        $presentation = $snapshot;
        $presentation['items'][0]['image_path'] = 'new.png';
        $presentation['items'][0]['translations'] = ['fr' => ['name' => 'Nouveau']];
        self::assertSame($version, GameRulesetVersion::of($presentation));

        $gameplay = $snapshot;
        $gameplay['items'][0]['price_coins'] = 2;
        self::assertNotSame($version, GameRulesetVersion::of($gameplay));
    }

    public function testHotReadDoesNotQueryConfigurationAgain(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(3))->method('fetchAssociative')->willReturn([
            'revision' => 1,
            'version' => GameRulesetVersion::of($snapshot),
            'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
        ]);
        $rulesets = new DatabaseGameRulesets($connection, new TagAwareAdapter(new ArrayAdapter()));

        $rulesets->snapshot();
        $rulesets->reset();
        $rulesets->snapshot();
        self::assertSame(1, $rulesets->revision());
    }

    public function testColdReadLoadsExactlyOnePublishedSnapshot(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(2))->method('fetchAssociative')->willReturn([
            'revision' => 3,
            'version' => GameRulesetVersion::of($snapshot),
            'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
        ]);

        $rulesets = new DatabaseGameRulesets($connection, new TagAwareAdapter(new ArrayAdapter()));

        self::assertSame(3, $rulesets->revision());
        self::assertSame($snapshot, $rulesets->snapshot());
    }

    public function testTwoReadersObserveTheSameCachedRevision(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(3))->method('fetchAssociative')->willReturn([
            'revision' => 5,
            'version' => GameRulesetVersion::of($snapshot),
            'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
        ]);
        $cache = new TagAwareAdapter(new ArrayAdapter());

        $first = new DatabaseGameRulesets($connection, $cache);
        $second = new DatabaseGameRulesets($connection, $cache);

        self::assertSame(5, $first->revision());
        self::assertSame(5, $second->revision());
        self::assertSame($first->snapshot(), $second->snapshot());
    }

    public function testDifferentSnapshotsWithTheSameRevisionNeverShareACacheEntry(): void
    {
        $firstSnapshot = $this->rulesets()->snapshot();
        /** @var array{items: list<array{price_coins: int}>} $firstSnapshot */
        $secondSnapshot = $firstSnapshot;
        $secondSnapshot['items'][1]['price_coins'] = 99;
        $firstVersion = GameRulesetVersion::of($firstSnapshot);
        $secondVersion = GameRulesetVersion::of($secondSnapshot);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(4))->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['revision' => 1, 'version' => $firstVersion, 'snapshot' => json_encode($firstSnapshot, \JSON_THROW_ON_ERROR)],
            ['revision' => 1, 'version' => $firstVersion, 'snapshot' => json_encode($firstSnapshot, \JSON_THROW_ON_ERROR)],
            ['revision' => 1, 'version' => $secondVersion, 'snapshot' => json_encode($secondSnapshot, \JSON_THROW_ON_ERROR)],
            ['revision' => 1, 'version' => $secondVersion, 'snapshot' => json_encode($secondSnapshot, \JSON_THROW_ON_ERROR)],
        );
        $cache = new TagAwareAdapter(new ArrayAdapter());

        $first = new DatabaseGameRulesets($connection, $cache);
        $second = new DatabaseGameRulesets($connection, $cache);

        self::assertSame(1, $first->revision());
        self::assertSame(1, $second->revision());
        $firstItems = $first->snapshot()['items'];
        $secondItems = $second->snapshot()['items'];
        self::assertIsArray($firstItems);
        self::assertIsArray($secondItems);
        /** @var list<array{price_coins: int}> $firstItems */
        /** @var list<array{price_coins: int}> $secondItems */
        self::assertSame(1, $firstItems[1]['price_coins']);
        self::assertSame(99, $secondItems[1]['price_coins']);
    }

    public function testSameSnapshotAtANewerRevisionRefreshesItsPublicationCache(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $version = GameRulesetVersion::of($snapshot);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(4))->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['revision' => 1, 'version' => $version],
            ['revision' => 1, 'version' => $version, 'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR)],
            ['revision' => 2, 'version' => $version],
            ['revision' => 2, 'version' => $version, 'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR)],
        );
        $rulesets = new DatabaseGameRulesets($connection, new TagAwareAdapter(new ArrayAdapter()));

        self::assertSame(1, $rulesets->revision());
        $rulesets->reset();
        self::assertSame(2, $rulesets->revision());
        self::assertSame($snapshot, $rulesets->snapshot());
    }

    public function testPresentationOnlyPublicationRefreshesTheCachedSnapshot(): void
    {
        $first = $this->rulesets()->snapshot();
        /** @var array{items: list<array{translations?: array<string, array{name: string}>}>} $first */
        $second = $first;
        $second['items'][1]['translations'] = ['fr' => ['name' => 'Bottes publiees']];
        $version = GameRulesetVersion::of($first);
        self::assertSame($version, GameRulesetVersion::of($second));

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(4))->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['revision' => 1, 'version' => $version],
            ['revision' => 1, 'version' => $version, 'snapshot' => json_encode($first, \JSON_THROW_ON_ERROR)],
            ['revision' => 2, 'version' => $version],
            ['revision' => 2, 'version' => $version, 'snapshot' => json_encode($second, \JSON_THROW_ON_ERROR)],
        );
        $cache = new TagAwareAdapter(new ArrayAdapter());
        $worker = new DatabaseGameRulesets($connection, $cache);

        self::assertSame(1, $worker->revision());
        $worker->reset();
        self::assertSame(2, $worker->revision());
        $items = $worker->snapshot()['items'];
        self::assertIsArray($items);
        /** @var list<array{translations: array{fr: array{name: string}}}> $items */
        self::assertSame('Bottes publiees', $items[1]['translations']['fr']['name']);
    }

    public function testInvalidatedCacheLoadsOneWholeNewRevision(): void
    {
        $first = $this->rulesets()->snapshot();
        /** @var array{items: list<array{price_coins: int}>} $first */
        /** @var array{items: list<array<string, mixed>>} $first */
        $second = $first;
        $second['items'][1]['price_coins'] = 99;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(4))->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['revision' => 1, 'version' => GameRulesetVersion::of($first), 'snapshot' => json_encode($first, \JSON_THROW_ON_ERROR)],
            ['revision' => 1, 'version' => GameRulesetVersion::of($first), 'snapshot' => json_encode($first, \JSON_THROW_ON_ERROR)],
            ['revision' => 2, 'version' => GameRulesetVersion::of($second), 'snapshot' => json_encode($second, \JSON_THROW_ON_ERROR)],
            ['revision' => 2, 'version' => GameRulesetVersion::of($second), 'snapshot' => json_encode($second, \JSON_THROW_ON_ERROR)],
        );
        $cache = new TagAwareAdapter(new ArrayAdapter());
        $rulesets = new DatabaseGameRulesets($connection, $cache);
        self::assertSame(1, $rulesets->revision());

        $cache->invalidateTags(['game.ruleset']);
        $rulesets->reset();
        self::assertSame(2, $rulesets->revision());
        $published = $rulesets->snapshot();
        /** @var array{items: list<array{price_coins: int}>} $published */
        self::assertSame(99, $published['items'][1]['price_coins']);
    }

    public function testIndependentMachineCachesObserveTheNewRevisionAfterResetEvenWhenInvalidationFailed(): void
    {
        $first = $this->rulesets()->snapshot();
        /** @var array{items: list<array{price_coins: int}>} $first */
        $second = $first;
        $second['items'][1]['price_coins'] = 99;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(4))->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['revision' => 1, 'version' => GameRulesetVersion::of($first), 'snapshot' => json_encode($first, \JSON_THROW_ON_ERROR)],
            ['revision' => 1, 'version' => GameRulesetVersion::of($first), 'snapshot' => json_encode($first, \JSON_THROW_ON_ERROR)],
            ['revision' => 2, 'version' => GameRulesetVersion::of($second), 'snapshot' => json_encode($second, \JSON_THROW_ON_ERROR)],
            ['revision' => 2, 'version' => GameRulesetVersion::of($second), 'snapshot' => json_encode($second, \JSON_THROW_ON_ERROR)],
        );

        // Deux caches filesystem distincts représentent app et worker Fly : la seconde
        // machine ne peut pas compter sur le tag invalidé par la première.
        $app = new DatabaseGameRulesets($connection, new TagAwareAdapter(new ArrayAdapter()));
        $worker = new DatabaseGameRulesets($connection, new TagAwareAdapter(new ArrayAdapter()));
        self::assertSame(1, $app->revision());
        self::assertSame(2, $worker->revision());
        $published = $worker->snapshot();
        /** @var array{items: list<array{price_coins: int}>} $published */
        self::assertSame(99, $published['items'][1]['price_coins']);
    }

    public function testCacheFailureNeverRejectsThePublishedDatabaseRuleset(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(2))->method('fetchAssociative')->willReturn([
            'revision' => 7,
            'version' => GameRulesetVersion::of($snapshot),
            'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
        ]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->willThrowException(new RuntimeException('Redis indisponible'));

        $rulesets = new DatabaseGameRulesets($connection, $cache);

        self::assertSame(7, $rulesets->revision());
        self::assertSame($snapshot, $rulesets->snapshot());
    }

    public function testPublicationBetweenPointerAndLoadRetriesTheNewWholeRevision(): void
    {
        /** @var array{items: list<array{price_coins: int}>} $first */
        $first = $this->rulesets()->snapshot();
        $second = $first;
        $second['items'][1]['price_coins'] = 42;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $connection->expects(self::exactly(4))->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['revision' => 4, 'version' => GameRulesetVersion::of($first), 'snapshot' => json_encode($first, \JSON_THROW_ON_ERROR)],
            false,
            ['revision' => 5, 'version' => GameRulesetVersion::of($second), 'snapshot' => json_encode($second, \JSON_THROW_ON_ERROR)],
            ['revision' => 5, 'version' => GameRulesetVersion::of($second), 'snapshot' => json_encode($second, \JSON_THROW_ON_ERROR)],
        );
        $rulesets = new DatabaseGameRulesets($connection, new TagAwareAdapter(new ArrayAdapter()));

        self::assertSame(5, $rulesets->revision());
        /** @var array{items: list<array{price_coins: int}>} $snapshot */
        $snapshot = $rulesets->snapshot();
        self::assertSame(42, $snapshot['items'][1]['price_coins']);
    }

    public function testLongLivedCatalogRebuildsOnlyWhenThePublishedRevisionChanges(): void
    {
        $first = $this->rulesets()->snapshot();
        /** @var array{items: list<array{price_coins: int}>} $first */
        $rulesets = new class($first) implements GameRulesets {
            public int $publishedRevision = 1;

            /** @param array<string, mixed> $publishedSnapshot */
            public function __construct(public array $publishedSnapshot)
            {
            }

            public function snapshot(): array
            {
                return $this->publishedSnapshot;
            }

            public function version(): string
            {
                return 'v1-test';
            }

            public function revision(): int
            {
                return $this->publishedRevision;
            }
        };
        $catalog = ItemCatalog::runtime($rulesets);
        $firstItem = $catalog->findHistorical('ACTIVE_BOOTS');
        self::assertNotNull($firstItem);
        self::assertSame(1, $firstItem->priceCoins);

        $published = $first;
        $published['items'][1]['price_coins'] = 9;
        $rulesets->publishedSnapshot = $published;
        ++$rulesets->publishedRevision;

        $publishedItem = $catalog->findHistorical('ACTIVE_BOOTS');
        self::assertNotNull($publishedItem);
        self::assertSame(9, $publishedItem->priceCoins);
    }

    public function testEveryRemainingBalanceObjectReadsThePublishedSnapshot(): void
    {
        $rulesets = $this->balanceRulesets();

        self::assertTrue(WorkoutRules::runtime($rulesets)->isTooShort(299));
        self::assertSame(Discipline::Running, ActivityTypeMap::runtime($rulesets)->disciplineFor('APPLE_HEALTH', 'running'));
        self::assertTrue(XpRates::runtime($rulesets)->credits(Discipline::Running));
        self::assertTrue(CreditingDisciplines::runtime($rulesets)->credits(Discipline::Running));
        self::assertSame(100, AttributeSplit::runtime($rulesets)->distribute(Discipline::Running, 100)->total());
        self::assertGreaterThan(0, Vitality::runtime($rulesets)->of(new AttributeGains(100, 100, 100, 100)));
        self::assertGreaterThan(0, DiminishingReturns::runtime($rulesets)->retain(0, 60));
        self::assertSame(1, LevelCurve::runtime($rulesets)->standingAt(0)->level);
        self::assertGreaterThanOrEqual(2, GuildRules::runtime($rulesets)->maximumMembers());
        self::assertGreaterThan(0, RisalaRules::runtime($rulesets)->recipientBonusPercent());
        self::assertFalse(QuietHours::runtime($rulesets)->contains(new DateTimeImmutable('2026-01-01 12:00:00+00:00'), Timezone::fromString('UTC')));
    }

    public function testInactiveDisciplineCannotCreditXpOrBeImported(): void
    {
        $rulesets = $this->balanceRulesets();
        /** @var list<array{discipline: string, active: bool}> $disciplines */
        $disciplines = $rulesets->publishedSnapshot['disciplines'];
        foreach ($disciplines as &$discipline) {
            if ('RUNNING' === $discipline['discipline']) {
                $discipline['active'] = false;
                break;
            }
        }
        unset($discipline);
        $rulesets->publishedSnapshot['disciplines'] = $disciplines;

        self::assertFalse(XpRates::runtime($rulesets)->credits(Discipline::Running));
        self::assertFalse(CreditingDisciplines::runtime($rulesets)->credits(Discipline::Running));
        self::assertNull(ActivityTypeMap::runtime($rulesets)->disciplineFor('APPLE_HEALTH', 'running'));
    }

    public function testActivityTypeOrderDoesNotChangeTheGameplayVersion(): void
    {
        $snapshot = $this->balanceRulesets()->snapshot();
        $version = GameRulesetVersion::of($snapshot);
        $activityTypes = $snapshot['activity_types'];
        self::assertIsArray($activityTypes);
        $snapshot['activity_types'] = array_reverse($activityTypes);

        self::assertSame($version, GameRulesetVersion::of($snapshot));
    }

    public function testRuntimeRulesRebuildAfterThePublishedRevisionChanges(): void
    {
        $rulesets = $this->balanceRulesets();
        $rules = WorkoutRules::runtime($rulesets);
        self::assertTrue($rules->isTooShort(299));

        /** @var array{training: array{minimum_duration_seconds: int}} $published */
        $published = $rulesets->publishedSnapshot;
        $published['training']['minimum_duration_seconds'] = 200;
        $rulesets->publishedSnapshot = $published;

        // La révision est le pointeur d'une publication : tant qu'elle ne bouge pas,
        // l'import qui a ouvert le snapshot garde exactement le même barème.
        self::assertTrue($rules->isTooShort(299));
        ++$rulesets->publishedRevision;

        self::assertFalse($rules->isTooShort(299));
    }

    public function testRuntimeRulesRebuildWhenTheVersionChangesAtTheSameRevision(): void
    {
        $rulesets = $this->balanceRulesets();
        $rules = WorkoutRules::runtime($rulesets);
        self::assertTrue($rules->isTooShort(299));

        /** @var array{training: array{minimum_duration_seconds: int}} $published */
        $published = $rulesets->publishedSnapshot;
        $published['training']['minimum_duration_seconds'] = 200;
        $rulesets->publishedSnapshot = $published;
        $rulesets->publishedVersion = 'v1-after-reset';

        self::assertFalse($rules->isTooShort(299));
    }

    public function testFailedRuntimeRebuildNeverKeepsThePreviousValueForTheNewPointer(): void
    {
        $rulesets = $this->balanceRulesets();
        $rules = WorkoutRules::runtime($rulesets);
        self::assertTrue($rules->isTooShort(299));

        /** @var array{training: array{minimum_duration_seconds: int}} $invalid */
        $invalid = $rulesets->publishedSnapshot;
        $invalid['training']['minimum_duration_seconds'] = -1;
        $rulesets->publishedSnapshot = $invalid;
        $rulesets->publishedRevision = 2;
        $rulesets->publishedVersion = 'v1-invalid';

        try {
            $rules->isTooShort(299);
            self::fail('La reconstruction invalide devait echouer.');
        } catch (Throwable) {
        }

        $valid = $invalid;
        $valid['training']['minimum_duration_seconds'] = 200;
        $rulesets->publishedSnapshot = $valid;

        self::assertFalse($rules->isTooShort(299));
    }

    private function rulesets(): GameRulesets
    {
        return new class implements GameRulesets {
            public function snapshot(): array
            {
                return [
                    'items' => [
                        ['key' => 'OLD_BOOTS', 'active' => false, 'rarity' => 'COMMON', 'kind' => 'EQUIPMENT', 'slot' => 'FEET', 'price_coins' => 1, 'modifiers' => [], 'shop' => ['available' => true, 'minimum_level' => 1]],
                        ['key' => 'ACTIVE_BOOTS', 'active' => true, 'rarity' => 'COMMON', 'kind' => 'EQUIPMENT', 'slot' => 'FEET', 'price_coins' => 1, 'modifiers' => [], 'shop' => ['available' => true, 'minimum_level' => 1]],
                    ],
                    'titles' => [
                        ['id' => 'old_title', 'active' => false, 'condition' => ['type' => 'session_count', 'threshold' => 1]],
                        ['id' => 'active_title', 'active' => true, 'condition' => ['type' => 'session_count', 'threshold' => 1]],
                    ],
                    'combat' => [
                        'fighter' => [],
                        'enemies' => [
                            ['key' => 'OLD_ENEMY', 'active' => false, 'level' => 2, 'hp' => 1, 'damage' => 0, 'mitigation_permille' => 0, 'extra_turn_permille' => 0, 'dodge_permille' => 0],
                            ['key' => 'ACTIVE_ENEMY', 'active' => true, 'level' => 1, 'hp' => 1, 'damage' => 0, 'mitigation_permille' => 0, 'extra_turn_permille' => 0, 'dodge_permille' => 0],
                        ],
                        'bosses' => [],
                    ],
                    'loot' => ['version' => 1, 'loot_luck' => ['floor_percent' => 0, 'cap_percent' => 100], 'workout' => [], 'adversary' => [], 'chest' => []],
                ];
            }

            public function version(): string
            {
                return 'v1-test';
            }

            public function revision(): int
            {
                return 1;
            }
        };
    }

    private function balanceRulesets(): MutableRulesets
    {
        return new MutableRulesets(GameRulesetSeed::data());
    }
}

/** @internal Fixture mutable pour prouver la relecture d'une publication. */
final class MutableRulesets implements GameRulesets
{
    public int $publishedRevision = 1;

    public string $publishedVersion = 'v1-test';

    /** @param array<string, mixed> $publishedSnapshot */
    public function __construct(public array $publishedSnapshot)
    {
    }

    public function snapshot(): array
    {
        return $this->publishedSnapshot;
    }

    public function version(): string
    {
        return $this->publishedVersion;
    }

    public function revision(): int
    {
        return $this->publishedRevision;
    }
}
