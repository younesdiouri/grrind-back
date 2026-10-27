<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\UI\Console\RetireLegacyEquipmentCommand;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class RetireLegacyEquipmentCommandTest extends KernelTestCase
{
    public function testDryRunRollbackTargetedPublicationAndIdempotentCleanup(): void
    {
        $db = self::getContainer()->get(Connection::class);
        $db->beginTransaction();
        try {
            $tables = ['game_ruleset', 'game_item', 'game_loot_table', 'game_recipe', 'game_settings', 'game_publication', 'game_artwork_pack', 'game_design_profile', 'rewards_inventory_item'];
            foreach ($tables as $table) {
                $db->executeStatement("CREATE TEMP TABLE {$table} (LIKE public.{$table} INCLUDING ALL) ON COMMIT DROP");
                $db->executeStatement("INSERT INTO {$table} SELECT * FROM public.{$table}");
            }
            $db->executeStatement('TRUNCATE rewards_inventory_item');
            $free = Uuid::v7()->toRfc4122();
            $busy = Uuid::v7()->toRfc4122();
            $retiredWeapon = Uuid::v7()->toRfc4122();
            foreach ([[$free, 'GOLDEN_BOXING_GLOVES', 'HANDS'], [$busy, 'GOLDEN_BOXING_GLOVES', 'HANDS'], [$busy, 'HALTER_EGO', 'WEAPON'], [$retiredWeapon, 'GOLDEN_BOXING_GLOVES', 'HANDS'], [$retiredWeapon, 'OBSIDIAN_WARBLADE', 'WEAPON']] as [$user, $key, $slot]) {
                $db->executeStatement('INSERT INTO rewards_inventory_item (id, user_id, item_key, quantity, slot, loot_roll_id, obtained_at) VALUES (?, ?, ?, 2, ?, NULL, CURRENT_TIMESTAMP)', [Uuid::v7()->toRfc4122(), $user, $key, $slot]);
            }
            // Une édition de prix/loot en attente ne doit jamais rejoindre le publié.
            $db->executeStatement("UPDATE game_item SET price_coins = 777 WHERE item_key = 'HALTER_EGO'");
            $db->executeStatement("UPDATE game_loot_table SET entries = '[{\"weight\":91},{\"item\":\"IRON_GAUNTLETS\",\"weight\":9}]', coins_minimum = 99, coins_maximum = 101 WHERE table_kind = 'chest' AND table_key = 'WOODEN_CHEST'");
            $db->executeStatement("UPDATE game_item SET slot = 'HANDS' WHERE item_key = 'GOLDEN_BOXING_GLOVES'");
            $stored = $db->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
            self::assertIsString($stored);
            /** @var array{items: list<array<string, mixed>>} $legacySnapshot */
            $legacySnapshot = json_decode($stored, true, flags: \JSON_THROW_ON_ERROR);
            foreach ($legacySnapshot['items'] as &$item) {
                if ('GOLDEN_BOXING_GLOVES' === $item['key']) {
                    $item['slot'] = 'HANDS';
                }
            }
            unset($item);
            $db->executeStatement('UPDATE game_ruleset SET snapshot = ? WHERE id = 1', [json_encode($legacySnapshot, \JSON_THROW_ON_ERROR)]);
            $db->executeStatement("UPDATE game_artwork_pack SET active = true WHERE pack_key = 'PURSUIT_GOGGLES'");
            $profileId = Uuid::v7()->toRfc4122();
            $db->executeStatement('INSERT INTO game_design_profile (id, name, strength, endurance, mobility, dexterity, vitality_override, equipment) VALUES (?, ?, 0, 0, 0, 0, NULL, ?)', [$profileId, 'Test retrait', '["IRON_GAUNTLETS","HALTER_EGO","GOLDEN_BOXING_GLOVES"]']);
            $facts = self::state($db, ['xp_transaction', 'rewards_coin_transaction', 'rewards_loot_roll', 'combat_battle']);
            $before = self::state($db, $tables);
            $tester = new CommandTester(self::getContainer()->get(RetireLegacyEquipmentCommand::class));
            self::assertSame(0, $tester->execute([]));
            self::assertSame($before, self::state($db, $tables), 'La simulation annule toutes les écritures, publication comprise.');
            self::assertSame(0, $tester->execute(['--apply' => true]));
            $result = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            self::assertSame(2, $result['glovesMovedToWeapon']);
            self::assertSame(1, $result['handsClearedToBag']);
            self::assertSame(1, $result['inventoryStacks']);
            self::assertSame(2, $result['inventoryUnits']);
            self::assertSame('WEAPON', $db->fetchOne('SELECT slot FROM rewards_inventory_item WHERE user_id = ? AND item_key = ?', [$free, 'GOLDEN_BOXING_GLOVES']));
            self::assertNull($db->fetchOne('SELECT slot FROM rewards_inventory_item WHERE user_id = ? AND item_key = ?', [$busy, 'GOLDEN_BOXING_GLOVES']));
            self::assertSame('WEAPON', $db->fetchOne('SELECT slot FROM rewards_inventory_item WHERE user_id = ? AND item_key = ?', [$busy, 'HALTER_EGO']));
            self::assertSame(2, self::number($db->fetchOne('SELECT quantity FROM rewards_inventory_item WHERE user_id = ? AND item_key = ?', [$busy, 'GOLDEN_BOXING_GLOVES'])));
            self::assertSame(0, self::number($db->fetchOne("SELECT COUNT(*) FROM rewards_inventory_item WHERE slot = 'HANDS'")));
            self::assertSame(0, self::number($db->fetchOne("SELECT COUNT(*) FROM game_artwork_pack WHERE active = true AND pack_key = 'PURSUIT_GOGGLES'")));
            self::assertSame('["HALTER_EGO"]', $db->fetchOne('SELECT equipment FROM game_design_profile WHERE id = ?', [$profileId]));
            self::assertSame($facts, self::state($db, array_keys($facts)));
            self::assertSame(11, self::number($db->fetchOne("SELECT COUNT(*) FROM game_item WHERE kind = 'EQUIPMENT'")), 'Les dix objets Unity et le Bandeau éclair (#325) restent.');
            self::assertSame(4, self::number($db->fetchOne("SELECT COUNT(*) FROM game_item WHERE kind = 'CHEST'")));
            self::assertSame(1, self::number($db->fetchOne("SELECT COUNT(*) FROM game_item WHERE kind = 'RESOURCE'")));
            self::assertSame(777, $db->fetchOne("SELECT price_coins FROM game_item WHERE item_key = 'HALTER_EGO'"));
            self::assertSame('[{"weight":91}]', $db->fetchOne("SELECT entries FROM game_loot_table WHERE table_kind = 'chest' AND table_key = 'WOODEN_CHEST'"));
            $published = $db->fetchAssociative('SELECT version, snapshot FROM game_ruleset WHERE id = 1');
            self::assertIsArray($published);
            self::assertIsString($published['snapshot']);
            /** @var array{items: list<array{key: string, slot: ?string, price_coins: int}>, recipes: array<mixed>, loot: array{chest: list<array{key: string, entries: list<array{item?: string, weight: int}>}>}} $snapshot */
            $snapshot = json_decode($published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($snapshot);
            self::assertSame(GameRulesetVersion::of($snapshot), $published['version']);
            $items = array_column($snapshot['items'], null, 'key');
            self::assertSame('WEAPON', $items['GOLDEN_BOXING_GLOVES']['slot']);
            self::assertNotSame(777, $items['HALTER_EGO']['price_coins']);
            self::assertArrayNotHasKey('IRON_GAUNTLETS', $items);
            self::assertSame([], $snapshot['recipes']);
            $chests = array_column($snapshot['loot']['chest'], null, 'key');
            self::assertSame(100, array_sum(array_column($chests['WOODEN_CHEST']['entries'], 'weight')));
            self::assertCount(10, $chests['WOODEN_CHEST']['entries']);
            $after = self::state($db, $tables);
            self::assertSame(0, $tester->execute(['--apply' => true]));
            self::assertSame($after, self::state($db, $tables), 'Une relance ne crée pas de nouvelle publication.');
            foreach ($before['game_publication'] as $publication) {
                self::assertContains($publication, $after['game_publication']);
            }
        } finally {
            $db->rollBack();
        }
    }

    private static function number(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @param list<string> $tables
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function state(Connection $db, array $tables): array
    {
        $state = [];
        foreach ($tables as $table) {
            $state[$table] = $db->fetchAllAssociative("SELECT * FROM {$table} ORDER BY id");
        }

        return $state;
    }
}
