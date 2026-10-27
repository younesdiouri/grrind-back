<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261027090000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BandeauEclairMigrationTest extends KernelTestCase
{
    public function testMigrationPublishesTheHeadbandWithoutTouchingDraftsHistoryOrLootAndIsIdempotent(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261027090000.php';
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            foreach (['game_item', 'game_loot_table', 'game_ruleset', 'game_publication'] as $table) {
                $db->executeStatement("CREATE TEMP TABLE {$table} (LIKE public.{$table} INCLUDING ALL) ON COMMIT DROP");
                $db->executeStatement("INSERT INTO {$table} SELECT * FROM public.{$table}");
            }
            $stored = $db->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
            self::assertIsString($stored);
            $snapshot = self::json($stored);
            self::assertIsArray($snapshot['items']);
            $snapshot['items'] = array_values(array_filter($snapshot['items'], static fn (mixed $item): bool => \is_array($item) && 'BANDEAU_ECLAIR' !== $item['key']));
            $db->executeStatement("DELETE FROM game_item WHERE item_key = 'BANDEAU_ECLAIR'");
            $db->executeStatement('UPDATE game_ruleset SET snapshot = ?, draft_revision = revision + 5 WHERE id = 1', [json_encode($snapshot, \JSON_THROW_ON_ERROR)]);
            $db->executeStatement("UPDATE game_item SET price_coins = 777 WHERE item_key = 'HALTER_EGO'");
            $loot = $db->fetchAllAssociative('SELECT * FROM game_loot_table ORDER BY id');
            $history = $db->fetchAllAssociative('SELECT * FROM game_publication ORDER BY id');
            $before = $db->fetchAssociative('SELECT revision, draft_revision FROM game_ruleset WHERE id = 1');
            self::assertIsArray($before);
            self::assertIsInt($before['revision']);
            self::assertIsInt($before['draft_revision']);
            self::apply($db);
            $published = $db->fetchAssociative('SELECT * FROM game_ruleset WHERE id = 1');
            self::assertIsArray($published);
            self::assertSame([$before['revision'] + 1, $before['draft_revision'] + 1], [$published['revision'], $published['draft_revision']]);
            self::assertIsString($published['snapshot']);
            /** @var array<string, mixed> $after */
            $after = self::json($published['snapshot']);
            self::assertSame(GameRulesetVersion::of($after), $published['version']);
            self::assertIsArray($after['items']);
            self::assertSame($snapshot['items'], \array_slice($after['items'], 0, -1));
            $headband = end($after['items']);
            self::assertIsArray($headband);
            self::assertSame(['BANDEAU_ECLAIR', 'HEAD', 1, 0, []], [$headband['key'], $headband['slot'], $headband['price_coins'], $headband['sell_price_coins'], $headband['modifiers']]);
            self::assertSame(777, $db->fetchOne("SELECT price_coins FROM game_item WHERE item_key = 'HALTER_EGO'"), 'Le brouillon en attente reste intact.');
            self::assertSame($loot, $db->fetchAllAssociative('SELECT * FROM game_loot_table ORDER BY id'), 'Hors coffre.');
            self::assertCount(\count($history) + 1, $db->fetchAllAssociative('SELECT id FROM game_publication'));
            $tables = [];
            foreach (['game_item', 'game_ruleset', 'game_publication'] as $table) {
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
        $migration = new Version20261027090000($db, new NullLogger());
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
