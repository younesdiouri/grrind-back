<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260908120000;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Les tables temporaires masquent les vraies tables : migration intégrale sur une base
 * v1 peuplée, sans toucher aux données locales. Le rollback retire toutes les fixtures. */
final class CombatV2MigrationTest extends KernelTestCase
{
    public function testUpgradePreservesDraftsAndRewardsAndOnlyPurgesBattleReplays(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20260908120000.php';
        require_once \dirname(__DIR__, 2).'/migrations/GameRulesetSeed.php';
        $seed = new ReflectionMethod('DoctrineMigrations\\GameRulesetSeed', 'data')->invoke(null);
        self::assertIsArray($seed);
        $snapshot = ['combat' => ['fighter' => $seed['fighter'], 'enemies' => $seed['enemies'], 'bosses' => $seed['bosses']], ...array_diff_key($seed, array_flip(['fighter', 'enemies', 'bosses']))];
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $db->beginTransaction();
        try {
            foreach ([
                'combat_battle' => 'id UUID, player_id UUID, turns INT',
                'shared_idempotency_key' => 'id INT, user_id UUID, response_status INT, response_body TEXT',
                'game_enemy' => 'extra_turn_permille INT',
                'game_item' => 'modifiers JSON',
                'game_settings' => 'id INT, fighter JSON',
                'game_ruleset' => 'id INT, revision INT, version TEXT, snapshot JSON, published_at TIMESTAMPTZ',
                'progression_snapshot' => 'sentinel TEXT',
                'rewards_inventory_item' => 'sentinel TEXT',
                'rewards_loot_roll' => 'sentinel TEXT',
                'rewards_coin_transaction' => 'sentinel TEXT',
            ] as $table => $columns) {
                $db->executeStatement("CREATE TEMP TABLE {$table} ({$columns}) ON COMMIT DROP");
            }
            $player = '00000000-0000-7000-8000-000000000001';
            $battle = '00000000-0000-7000-8000-000000000002';
            $db->insert('combat_battle', ['id' => $battle, 'player_id' => $player, 'turns' => 12]);
            $body = json_encode(['id' => $battle, 'player' => [], 'enemy' => [], 'events' => []], \JSON_THROW_ON_ERROR);
            foreach ([1 => $body, 2 => 'non-json', 3 => '{"id":"other-operation"}'] as $id => $response) {
                $db->insert('shared_idempotency_key', ['id' => $id, 'user_id' => $player, 'response_status' => 201, 'response_body' => $response]);
            }
            $db->insert('shared_idempotency_key', ['id' => 4, 'user_id' => $battle, 'response_status' => 201, 'response_body' => $body]);
            $db->insert('game_enemy', ['extra_turn_permille' => 123]);
            $db->insert('game_item', ['modifiers' => '[{"type":"EXTRA_TURN_BONUS","value":28},{"type":"HP_BONUS","value":3}]']);
            self::assertIsArray($seed['fighter']);
            $draft = [...$seed['fighter'], 'base_damage' => 99];
            $db->insert('game_settings', ['id' => 1, 'fighter' => json_encode($draft, \JSON_THROW_ON_ERROR)]);
            $db->insert('game_ruleset', ['id' => 1, 'revision' => 7, 'version' => 'old', 'snapshot' => json_encode($snapshot, \JSON_THROW_ON_ERROR)]);
            foreach (['progression_snapshot', 'rewards_inventory_item', 'rewards_loot_roll', 'rewards_coin_transaction'] as $table) {
                $db->insert($table, ['sentinel' => $battle]);
            }
            $migration = new Version20260908120000($db, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                self::assertSame([], $query->getTypes());
                $db->executeStatement($query->getStatement(), array_values($query->getParameters()));
            }
            self::assertSame(0, $db->fetchOne('SELECT COUNT(*) FROM combat_battle'));
            self::assertSame([2, 3, 4], $db->fetchFirstColumn('SELECT id FROM shared_idempotency_key ORDER BY id'));
            self::assertSame(123, $db->fetchOne('SELECT combo_permille FROM game_enemy'));
            self::assertSame(0, $db->fetchOne('SELECT maintenance_permille FROM game_enemy'));
            self::assertSame(99, $db->fetchOne("SELECT (fighter->>'base_damage')::int FROM game_settings"));
            self::assertSame('COMBO_BONUS', $db->fetchOne("SELECT modifiers->0->>'type' FROM game_item"));
            self::assertSame('HP_BONUS', $db->fetchOne("SELECT modifiers->1->>'type' FROM game_item"));
            foreach (['progression_snapshot', 'rewards_inventory_item', 'rewards_loot_roll', 'rewards_coin_transaction'] as $table) {
                self::assertSame($battle, $db->fetchOne("SELECT sentinel FROM {$table}"));
            }
            $json = $db->fetchOne('SELECT snapshot FROM game_ruleset');
            self::assertIsString($json);
            /** @var array<string, mixed> $published */
            $published = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            self::assertSame(GameRulesetVersion::of($published), $db->fetchOne('SELECT version FROM game_ruleset'));
            self::assertSame(8, $db->fetchOne('SELECT revision FROM game_ruleset'));
            self::assertSame(16, $db->fetchOne("SELECT (snapshot->'combat'->'fighter'->>'base_damage')::int FROM game_ruleset"));
            new ReflectionMethod(GameRulesetPublisher::class, 'validate')->invoke(null, $published);
        } finally {
            $db->rollBack();
        }
    }
}
