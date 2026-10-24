<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261024204455;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SportsWeaponsMigrationTest extends KernelTestCase
{
    public function testMigrationPreservesDraftsHistoryAndIsIdempotent(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261024204455.php';
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            foreach (['game_item', 'game_loot_table', 'game_settings', 'game_ruleset', 'game_publication'] as $table) {
                $db->executeStatement("CREATE TEMP TABLE {$table} (LIKE public.{$table} INCLUDING ALL) ON COMMIT DROP");
                $db->executeStatement("INSERT INTO {$table} SELECT * FROM public.{$table}");
            }
            $stored = $db->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
            self::assertIsString($stored);
            $snapshot = self::json($stored);
            /** @var list<array<string, mixed>> $items */
            $items = $snapshot['items'];
            $snapshot['items'] = array_values(array_filter($items, static fn (array $item): bool => !\in_array($item['key'], ['FER_PLAY', 'HUILESSON'], true)));
            foreach ($snapshot['items'] as &$item) {
                unset($item['weapon_type']);
            }
            unset($item);
            /** @var array{version: int, chest: list<array{key: string, entries: list<array{item?: string, weight: int}>}>} $loot */
            $loot = $snapshot['loot'];
            foreach ($loot['chest'] as &$chest) {
                $chest['entries'] = array_values(array_filter($chest['entries'], static fn (array $entry): bool => !\in_array($entry['item'] ?? null, ['FER_PLAY', 'HUILESSON'], true)));
            }
            unset($chest);
            $snapshot['loot'] = $loot;
            $db->executeStatement("DELETE FROM game_item WHERE item_key IN ('FER_PLAY', 'HUILESSON')");
            $db->executeStatement('ALTER TABLE game_item DROP COLUMN weapon_type');
            $db->executeStatement('UPDATE game_ruleset SET snapshot = ?, draft_revision = revision + 5 WHERE id = 1', [json_encode($snapshot, \JSON_THROW_ON_ERROR)]);
            $db->executeStatement("UPDATE game_item SET price_coins = 777 WHERE item_key = 'HALTER_EGO'");
            $db->executeStatement("UPDATE game_loot_table SET active = false, entries = '[{\"weight\":99}]', coins_minimum = 99, coins_maximum = 101 WHERE table_kind = 'chest' AND table_key = 'WOODEN_CHEST'");
            $beforeItems = $db->fetchAllAssociative('SELECT * FROM game_item ORDER BY item_key');
            $history = $db->fetchAllAssociative('SELECT * FROM game_publication ORDER BY id');
            $before = $db->fetchAssociative('SELECT * FROM game_ruleset WHERE id = 1');
            self::assertIsArray($before);
            self::assertIsInt($before['revision']);
            self::assertIsInt($before['draft_revision']);
            self::apply($db);
            $published = $db->fetchAssociative('SELECT * FROM game_ruleset WHERE id = 1');
            self::assertIsArray($published);
            self::assertSame($before['revision'] + 1, $published['revision']);
            self::assertSame($before['draft_revision'] + 1, $published['draft_revision']);
            self::assertIsString($published['snapshot']);
            /** @var array<string, mixed> $after */
            $after = self::json($published['snapshot']);
            self::assertSame(GameRulesetVersion::of($after), $published['version']);
            self::assertIsArray($after['items']);
            $expectedItems = array_map(static fn (array $item): array => 'WEAPON' === ($item['slot'] ?? null) ? $item + ['weapon_type' => 'FIST'] : $item, $snapshot['items']);
            self::assertSame($expectedItems, \array_slice($after['items'], 0, -2));
            self::assertIsArray($after['loot']);
            self::assertSame($loot['version'] + 1, $after['loot']['version']);
            $draft = $db->fetchAssociative("SELECT * FROM game_loot_table WHERE table_kind = 'chest' AND table_key = 'WOODEN_CHEST'");
            self::assertIsArray($draft);
            self::assertFalse($draft['active']);
            self::assertSame(99, $draft['coins_minimum']);
            self::assertIsString($draft['entries']);
            self::assertSame([['weight' => 99], ['item' => 'FER_PLAY', 'weight' => 5], ['item' => 'HUILESSON', 'weight' => 5]], self::json($draft['entries']));
            foreach ($beforeItems as $item) {
                self::assertSame($item + ['weapon_type' => 'WEAPON' === $item['slot'] ? 'FIST' : null], $db->fetchAssociative('SELECT * FROM game_item WHERE id = ?', [$item['id']]));
            }
            foreach ($history as $publication) {
                self::assertSame($publication, $db->fetchAssociative('SELECT * FROM game_publication WHERE id = ?', [$publication['id']]));
            }
            $tables = [];
            foreach (['game_item', 'game_loot_table', 'game_settings', 'game_ruleset', 'game_publication'] as $table) {
                $tables[$table] = $db->fetchAllAssociative("SELECT * FROM {$table} ORDER BY id");
            }
            self::apply($db);
            foreach ($tables as $table => $rows) {
                self::assertSame($rows, $db->fetchAllAssociative("SELECT * FROM {$table} ORDER BY id"), $table.' est inchangé au second passage.');
            }
        } finally {
            $db->rollBack();
        }
    }

    private static function apply(Connection $db): void
    {
        $migration = new Version20261024204455($db, new NullLogger());
        $migration->up(new Schema());
        foreach ($migration->getSql() as $query) {
            $db->executeStatement($query->getStatement(), array_values($query->getParameters()));
        }
    }

    /** @return array<mixed> */
    private static function json(string $json): array
    {
        $decoded = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
