<?php

declare(strict_types=1);

namespace App\Tests\Shared\Config;

use App\Combat\Domain\EnemyCatalog;
use App\Rewards\Domain\ItemCatalog;
use App\Shared\Application\GameRulesets;
use App\Shared\Infrastructure\Config\DatabaseGameRulesets;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\CacheInterface;

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

    public function testHybridVersionChangesForGameplayButNotPresentation(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        /** @var array{items: list<array<string, mixed>>} $snapshot */
        $version = GameRulesetVersion::of('v1-yaml', $snapshot);
        $presentation = $snapshot;
        $presentation['items'][0]['image_path'] = 'new.png';
        $presentation['items'][0]['translations'] = ['fr' => ['name' => 'Nouveau']];
        self::assertSame($version, GameRulesetVersion::of('v1-yaml', $presentation));

        $gameplay = $snapshot;
        $gameplay['items'][0]['price_coins'] = 2;
        self::assertNotSame($version, GameRulesetVersion::of('v1-yaml', $gameplay));
        self::assertNotSame($version, GameRulesetVersion::of('v1-other-yaml', $snapshot));
    }

    public function testHotReadDoesNotQueryConfigurationAgain(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'revision' => 1,
            'version' => 'ignored-at-runtime',
            'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
        ]);
        $rulesets = new DatabaseGameRulesets($connection, new TagAwareAdapter(new ArrayAdapter()), 'v1-yaml');

        $rulesets->snapshot();
        $rulesets->reset();
        $rulesets->snapshot();
        self::assertSame(1, $rulesets->revision());
    }

    public function testColdReadLoadsExactlyOnePublishedSnapshot(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'revision' => 3,
            'version' => 'ignored-at-runtime',
            'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
        ]);

        $rulesets = new DatabaseGameRulesets($connection, new TagAwareAdapter(new ArrayAdapter()), 'v1-yaml');

        self::assertSame(3, $rulesets->revision());
        self::assertSame($snapshot, $rulesets->snapshot());
    }

    public function testTwoReadersObserveTheSameCachedRevision(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'revision' => 5,
            'version' => 'ignored-at-runtime',
            'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
        ]);
        $cache = new TagAwareAdapter(new ArrayAdapter());

        $first = new DatabaseGameRulesets($connection, $cache, 'v1-yaml');
        $second = new DatabaseGameRulesets($connection, $cache, 'v1-yaml');

        self::assertSame(5, $first->revision());
        self::assertSame(5, $second->revision());
        self::assertSame($first->snapshot(), $second->snapshot());
    }

    public function testInvalidatedCacheLoadsOneWholeNewRevision(): void
    {
        $first = $this->rulesets()->snapshot();
        /** @var array{items: list<array{price_coins: int}>} $first */
        /** @var array{items: list<array<string, mixed>>} $first */
        $second = $first;
        $second['items'][1]['price_coins'] = 99;
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['revision' => 1, 'version' => 'ignored', 'snapshot' => json_encode($first, \JSON_THROW_ON_ERROR)],
            ['revision' => 2, 'version' => 'ignored', 'snapshot' => json_encode($second, \JSON_THROW_ON_ERROR)],
        );
        $cache = new TagAwareAdapter(new ArrayAdapter());
        $rulesets = new DatabaseGameRulesets($connection, $cache, 'v1-yaml');
        self::assertSame(1, $rulesets->revision());

        $cache->invalidateTags(['game.ruleset']);
        $rulesets->reset();
        self::assertSame(2, $rulesets->revision());
        $published = $rulesets->snapshot();
        /** @var array{items: list<array{price_coins: int}>} $published */
        self::assertSame(99, $published['items'][1]['price_coins']);
    }

    public function testCacheFailureNeverRejectsThePublishedDatabaseRuleset(): void
    {
        $snapshot = $this->rulesets()->snapshot();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'revision' => 7,
            'version' => 'ignored-at-runtime',
            'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR),
        ]);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects(self::once())->method('get')->willThrowException(new RuntimeException('Redis indisponible'));

        $rulesets = new DatabaseGameRulesets($connection, $cache, 'v1-yaml');

        self::assertSame(7, $rulesets->revision());
        self::assertSame($snapshot, $rulesets->snapshot());
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
                    'titles' => [],
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
}
