<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261023180000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** @phpstan-type Snapshot array{items: list<array<string, mixed>>, loot: array{version: int, chest: list<array{key: string, entries: list<array{item?: string, weight: int}>}>}} */
final class UnityEquipmentMigrationTest extends KernelTestCase
{
    public function testOnlyNewItemsAndDropsArePublishedWhileExistingDraftsAndHistorySurvive(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261023180000.php';
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            foreach (['game_item', 'game_loot_table', 'game_settings', 'game_ruleset', 'game_publication'] as $table) {
                $db->executeStatement("CREATE TEMP TABLE {$table} (LIKE public.{$table} INCLUDING ALL) ON COMMIT DROP");
                $db->executeStatement("INSERT INTO {$table} SELECT * FROM public.{$table}");
            }
            /** @var list<array{key: string}> $definitions */
            $definitions = self::json((string) file_get_contents(\dirname(__DIR__, 2).'/migrations/data/unity-equipment.json'));
            $keys = array_column($definitions, 'key');
            $stored = $db->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
            self::assertIsString($stored);
            /** @var Snapshot $snapshot */
            $snapshot = self::json($stored);
            $snapshot['items'] = self::withoutNewItems($snapshot['items'], $keys, 'key');
            foreach ($snapshot['loot']['chest'] as &$table) {
                $table['entries'] = self::withoutNewItems($table['entries'], $keys, 'item');
            }
            unset($table);
            foreach ($keys as $key) {
                $db->executeStatement('DELETE FROM game_item WHERE item_key = ?', [$key]);
            }
            $revision = $db->fetchOne('SELECT revision FROM game_ruleset WHERE id = 1');
            self::assertIsInt($revision);
            ++$revision;
            $db->executeStatement('UPDATE game_ruleset SET snapshot = ?, revision = ?, draft_revision = ? WHERE id = 1', [json_encode($snapshot, \JSON_THROW_ON_ERROR), $revision, $revision + 5]);
            $db->executeStatement("UPDATE game_item SET price_coins = 777 WHERE item_key = 'WORN_RUNNING_SHOES'");
            $db->executeStatement("UPDATE game_loot_table SET active = false, entries = '[{\"weight\":99}]', coins_minimum = 99, coins_maximum = 101 WHERE table_kind = 'chest' AND table_key = 'WOODEN_CHEST'");
            $db->executeStatement('UPDATE game_settings SET loot_version = 77 WHERE id = 1');
            $beforeItems = $db->fetchAllAssociative('SELECT * FROM game_item ORDER BY item_key');
            $beforeHistory = $db->fetchAllAssociative('SELECT * FROM game_publication ORDER BY id');
            $migration = new Version20261023180000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $db->executeStatement($query->getStatement(), array_values($query->getParameters()));
            }
            $published = $db->fetchAssociative('SELECT * FROM game_ruleset WHERE id = 1');
            self::assertIsArray($published);
            self::assertIsString($published['snapshot']);
            /** @var Snapshot $after */
            $after = self::json($published['snapshot']);
            self::assertSame($revision + 1, $published['revision']);
            self::assertSame($revision + 6, $published['draft_revision']);
            self::assertSame(GameRulesetVersion::of($after), $published['version']);
            self::assertSame($snapshot['loot']['version'] + 1, $after['loot']['version']);
            self::assertSame(78, $db->fetchOne('SELECT loot_version FROM game_settings WHERE id = 1'));
            $draft = $db->fetchAssociative("SELECT * FROM game_loot_table WHERE table_kind = 'chest' AND table_key = 'WOODEN_CHEST'");
            self::assertIsArray($draft);
            self::assertFalse($draft['active']);
            self::assertSame(99, $draft['coins_minimum']);
            self::assertSame(101, $draft['coins_maximum']);
            $drops = array_map(static fn (string $key): array => ['item' => $key, 'weight' => 5], $keys);
            self::assertIsString($draft['entries']);
            self::assertSame([['weight' => 99], ...$drops], self::json($draft['entries']));
            self::assertCount(\count($beforeItems) + 7, $db->fetchAllAssociative('SELECT * FROM game_item'));
            foreach ($beforeItems as $item) {
                self::assertSame($item, $db->fetchAssociative('SELECT * FROM game_item WHERE id = ?', [$item['id']]));
            }
            foreach ($beforeHistory as $publication) {
                self::assertSame($publication, $db->fetchAssociative('SELECT * FROM game_publication WHERE id = ?', [$publication['id']]));
            }
            $history = $db->fetchAssociative('SELECT * FROM game_publication WHERE revision = ?', [$revision + 1]);
            self::assertIsArray($history);
            self::assertIsString($history['snapshot']);
            self::assertSame($after, self::json($history['snapshot']));
            self::assertSame($published['version'], $history['version']);
            self::assertSame($snapshot['items'], \array_slice($after['items'], 0, -7));
            $snapshot['items'] = $after['items'];
            ++$snapshot['loot']['version'];
            foreach ($snapshot['loot']['chest'] as &$table) {
                if ('WOODEN_CHEST' === $table['key']) {
                    $table['entries'] = [...$table['entries'], ...$drops];
                }
            }
            unset($table);
            self::assertSame($snapshot, $after, 'Aucun autre réglage ni brouillon ne rejoint le publié.');
        } finally {
            $db->rollBack();
        }
    }

    /** @return array<mixed> */
    private static function json(string $json): array
    {
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<string>               $keys
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutNewItems(array $items, array $keys, string $field): array
    {
        return array_values(array_filter($items, static fn (array $item): bool => !\in_array($item[$field] ?? null, $keys, true)));
    }
}
