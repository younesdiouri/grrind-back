<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261101112442;
use DoctrineMigrations\Version20261101112859;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WeeklyRulesMigrationTest extends KernelTestCase
{
    public function testNewRulesPreserveHistoricalRaidsAndUnpublishedDraftChanges(): void
    {
        require_once \dirname(__DIR__, 2).'/migrations/Version20261101112442.php';
        require_once \dirname(__DIR__, 2).'/migrations/Version20261101112859.php';
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            foreach ([
                'community_guild_membership' => 'id INT',
                'community_alam_run' => 'id INT, resolved_at TIMESTAMPTZ, rules JSON, ruleset_version TEXT, result JSON',
                'game_settings' => 'id INT, alam JSON, formulas JSON',
                'game_item' => 'modifiers JSON',
                'game_ruleset' => 'id INT, revision INT, draft_revision INT, version TEXT, snapshot JSON, published_at TIMESTAMPTZ',
                'game_publication' => 'id UUID, published_at TIMESTAMPTZ, revision INT, author TEXT, version TEXT, snapshot JSON',
            ] as $table => $columns) {
                $db->executeStatement("CREATE TEMP TABLE {$table} ({$columns}) ON COMMIT DROP");
            }
            $snapshot = GameDesignFixture::snapshot();
            $snapshot['items'] = [['key' => 'TEST', 'modifiers' => [['type' => 'STRENGTH_BONUS', 'value' => 350], ['type' => 'DAMAGE_BONUS', 'value' => 2]]]];
            $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
            $db->insert('game_ruleset', ['id' => 1, 'revision' => 1, 'draft_revision' => 1, 'version' => 'old', 'snapshot' => $encoded]);
            $db->insert('game_settings', ['id' => 1, 'alam' => '{"strength_target":777}', 'formulas' => '{"hp":{},"damage":{},"dodge":{}}']);
            $db->insert('game_item', ['modifiers' => '[{"type":"STRENGTH_BONUS","value":350},{"type":"DAMAGE_BONUS","value":2}]']);
            $db->insert('community_guild_membership', ['id' => 1]);
            $historical = '{"encounters":[{"index":1},{"index":2},{"index":3}]}';
            $db->insert('community_alam_run', ['id' => 1, 'resolved_at' => '2026-10-25 19:00:00+01', 'rules' => $encoded, 'ruleset_version' => 'old', 'result' => $historical]);
            $db->insert('community_alam_run', ['id' => 2, 'rules' => $encoded, 'ruleset_version' => 'old', 'result' => '{}']);
            foreach ([Version20261101112442::class, Version20261101112859::class] as $class) {
                $migration = new $class($db, new NullLogger());
                $migration->up(new Schema());
                foreach ($migration->getSql() as $query) {
                    $db->executeStatement($query->getStatement(), array_values($query->getParameters()));
                }
            }
            self::assertFalse($db->fetchOne('SELECT alam_registered FROM community_guild_membership'));
            self::assertNull($db->fetchOne('SELECT difficulty FROM community_alam_run WHERE id = 1'));
            self::assertSame('EASY', $db->fetchOne('SELECT difficulty FROM community_alam_run WHERE id = 2'));
            self::assertSame($historical, $db->fetchOne('SELECT result FROM community_alam_run WHERE id = 1'));
            self::assertSame($encoded, $db->fetchOne('SELECT rules FROM community_alam_run WHERE id = 1'));
            self::assertSame('old', $db->fetchOne('SELECT ruleset_version FROM community_alam_run WHERE id = 1'));
            self::assertSame('777', $db->fetchOne("SELECT alam->>'strength_target' FROM game_settings"));
            self::assertSame('[{"type": "DAMAGE_BONUS", "value": 2}]', $db->fetchOne('SELECT modifiers::text FROM game_item'));
            foreach (['SELECT snapshot FROM game_ruleset' => 'SELECT version FROM game_ruleset', 'SELECT rules FROM community_alam_run WHERE id = 2' => 'SELECT ruleset_version FROM community_alam_run WHERE id = 2'] as $read => $version) {
                $json = $db->fetchOne($read);
                self::assertIsString($json);
                /** @var array<string, mixed> $rules */
                $rules = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
                self::assertSame(GameRulesetVersion::of($rules), $db->fetchOne($version));
            }
            self::assertSame(2, $db->fetchOne('SELECT COUNT(*) FROM game_publication'));
        } finally {
            $db->rollBack();
        }
    }
}
