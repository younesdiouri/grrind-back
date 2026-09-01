<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260901150553 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin : persiste et publie la configuration de jeu initialement livrée en YAML (#260)';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/GameRulesetSeed.php';
        $this->addSql('CREATE TABLE game_enemy (id UUID NOT NULL, enemy_key VARCHAR(64) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL, boss BOOLEAN NOT NULL, minimum_level INT NOT NULL, hp INT NOT NULL, damage INT NOT NULL, mitigation_permille INT NOT NULL, extra_turn_permille INT NOT NULL, dodge_permille INT NOT NULL, translations JSON NOT NULL, PRIMARY KEY (id), CONSTRAINT game_enemy_sort_order_unique UNIQUE (boss, sort_order))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_enemy_key ON game_enemy (enemy_key)');
        $this->addSql('CREATE TABLE game_item (id UUID NOT NULL, item_key VARCHAR(64) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL UNIQUE, rarity VARCHAR(20) NOT NULL, kind VARCHAR(20) NOT NULL, slot VARCHAR(30) DEFAULT NULL, price_coins INT NOT NULL CHECK (price_coins >= 0), modifiers JSON NOT NULL, shop_available BOOLEAN NOT NULL, shop_minimum_level INT DEFAULT NULL CHECK (shop_minimum_level IS NULL OR shop_minimum_level >= 1), image_path VARCHAR(255) NOT NULL, translations JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_item_key ON game_item (item_key)');
        $this->addSql('CREATE TABLE game_loot_table (id UUID NOT NULL, table_kind VARCHAR(20) NOT NULL, table_key VARCHAR(64) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL, eligibility JSON DEFAULT NULL, coins_minimum INT NOT NULL CHECK (coins_minimum >= 0), coins_maximum INT NOT NULL CHECK (coins_maximum >= coins_minimum), entries JSON NOT NULL, PRIMARY KEY (id), CONSTRAINT game_loot_sort_order_unique UNIQUE (table_kind, sort_order))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_loot_table_kind_key ON game_loot_table (table_kind, table_key)');
        $this->addSql('CREATE TABLE game_ruleset (id INT NOT NULL, revision INT NOT NULL, version VARCHAR(32) NOT NULL, snapshot JSON NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE game_settings (id INT NOT NULL, fighter JSON NOT NULL, loot_luck JSON NOT NULL, loot_version INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE game_title (id UUID NOT NULL, title_key VARCHAR(64) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL UNIQUE, condition_type VARCHAR(40) NOT NULL, threshold INT NOT NULL CHECK (threshold >= 0), discipline VARCHAR(30) DEFAULT NULL, translations JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_title_key ON game_title (title_key)');
        $seed = GameRulesetSeed::data();
        /** @var list<array<string, mixed>> $items */
        $items = $seed['items'];
        /** @var list<array<string, mixed>> $titles */
        $titles = $seed['titles'];
        /** @var list<array<string, mixed>> $enemies */
        $enemies = [...$seed['enemies'], ...$seed['bosses']];
        /** @var array<string, mixed> $loot */
        $loot = $seed['loot'];
        /** @var array<string, int> $fighter */
        $fighter = $seed['fighter'];

        foreach ($items as $item) {
            $this->insert('game_item', [
                'id' => Uuid::v7()->toRfc4122(), 'item_key' => $item['key'], 'active' => $item['active'], 'sort_order' => $item['sort_order'],
                'rarity' => $item['rarity'], 'kind' => $item['kind'] ?? 'EQUIPMENT', 'slot' => $item['slot'] ?? null,
                'price_coins' => $item['price_coins'], 'modifiers' => json_encode($item['modifiers'] ?? [], JSON_THROW_ON_ERROR),
                'shop_available' => $item['shop']['available'] ?? false, 'shop_minimum_level' => $item['shop']['minimum_level'] ?? null,
                'image_path' => $item['image_path'], 'translations' => json_encode($item['translations'], JSON_THROW_ON_ERROR),
            ]);
        }
        foreach ($titles as $title) {
            $this->insert('game_title', [
                'id' => Uuid::v7()->toRfc4122(), 'title_key' => $title['id'], 'active' => $title['active'], 'sort_order' => $title['sort_order'],
                'condition_type' => $title['condition']['type'], 'threshold' => $title['condition']['threshold'], 'discipline' => $title['condition']['discipline'] ?? null,
                'translations' => json_encode($title['translations'], JSON_THROW_ON_ERROR),
            ]);
        }
        foreach ($enemies as $enemy) {
            $this->insert('game_enemy', [
                'id' => Uuid::v7()->toRfc4122(), 'enemy_key' => $enemy['key'], 'active' => $enemy['active'], 'sort_order' => $enemy['sort_order'],
                'boss' => $enemy['boss'], 'minimum_level' => $enemy['minimum_level'], 'hp' => $enemy['hp'], 'damage' => $enemy['damage'],
                'mitigation_permille' => $enemy['mitigation_permille'], 'extra_turn_permille' => $enemy['extra_turn_permille'],
                'dodge_permille' => $enemy['dodge_permille'], 'translations' => json_encode($enemy['translations'], JSON_THROW_ON_ERROR),
            ]);
        }
        foreach (['workout', 'adversary', 'chest'] as $kind) {
            /** @var list<array<string, mixed>> $tables */
            $tables = $loot[$kind];
            foreach ($tables as $sortOrder => $table) {
                $this->insert('game_loot_table', [
                    'id' => Uuid::v7()->toRfc4122(), 'table_kind' => $kind, 'table_key' => $table['key'], 'active' => true, 'sort_order' => $sortOrder,
                    'eligibility' => isset($table['eligibility']) ? json_encode($table['eligibility'], JSON_THROW_ON_ERROR) : null,
                    'coins_minimum' => $table['coins']['minimum'], 'coins_maximum' => $table['coins']['maximum'],
                    'entries' => json_encode($table['entries'], JSON_THROW_ON_ERROR),
                ]);
            }
        }
        /** @var array{floor_percent: int, cap_percent: int} $lootLuck */
        $lootLuck = $loot['loot_luck'];
        $this->insert('game_settings', ['id' => 1, 'fighter' => json_encode($fighter, JSON_THROW_ON_ERROR), 'loot_luck' => json_encode($lootLuck, JSON_THROW_ON_ERROR), 'loot_version' => 1]);

        // Même forme que `GameRulesetPublisher`: le seed est déjà une publication, pas une
        // préforme qui ferait changer gameplay/hash lors de sa première édition visuelle.
        $snapshot = [
            'items' => array_map(static fn (array $item): array => [
                'key' => $item['key'], 'active' => $item['active'], 'rarity' => $item['rarity'], 'kind' => $item['kind'] ?? 'EQUIPMENT', 'slot' => $item['slot'] ?? null,
                'price_coins' => $item['price_coins'], 'modifiers' => $item['modifiers'] ?? [], 'shop' => ['available' => $item['shop']['available'] ?? false, 'minimum_level' => $item['shop']['minimum_level'] ?? null],
                'image_path' => $item['image_path'], 'translations' => $item['translations'],
            ], $items),
            'titles' => array_map(static fn (array $title): array => [
                'id' => $title['id'], 'active' => $title['active'], 'condition' => ['type' => $title['condition']['type'], 'threshold' => $title['condition']['threshold'], 'discipline' => $title['condition']['discipline'] ?? null], 'translations' => $title['translations'],
            ], $titles),
            'combat' => [
                'fighter' => $fighter,
                'enemies' => array_map(static fn (array $enemy): array => ['key' => $enemy['key'], 'active' => $enemy['active'], 'hp' => $enemy['hp'], 'damage' => $enemy['damage'], 'mitigation_permille' => $enemy['mitigation_permille'], 'extra_turn_permille' => $enemy['extra_turn_permille'], 'dodge_permille' => $enemy['dodge_permille'], 'translations' => $enemy['translations'], 'level' => $enemy['minimum_level']], $seed['enemies']),
                'bosses' => array_map(static fn (array $enemy): array => ['key' => $enemy['key'], 'active' => $enemy['active'], 'hp' => $enemy['hp'], 'damage' => $enemy['damage'], 'mitigation_permille' => $enemy['mitigation_permille'], 'extra_turn_permille' => $enemy['extra_turn_permille'], 'dodge_permille' => $enemy['dodge_permille'], 'translations' => $enemy['translations'], 'minimum_level' => $enemy['minimum_level']], $seed['bosses']),
            ],
            'loot' => [
                'version' => 1, 'loot_luck' => $loot['loot_luck'],
                'workout' => array_map(static fn (array $table): array => ['key' => $table['key'], 'active' => true, 'coins' => $table['coins'], 'entries' => $table['entries'], 'eligibility' => $table['eligibility']], $loot['workout']),
                'adversary' => array_map(static fn (array $table): array => ['key' => $table['key'], 'active' => true, 'coins' => $table['coins'], 'entries' => $table['entries']], $loot['adversary']),
                'chest' => array_map(static fn (array $table): array => ['key' => $table['key'], 'active' => true, 'coins' => $table['coins'], 'entries' => $table['entries']], $loot['chest']),
            ],
        ];
        $this->insert('game_ruleset', [
            'id' => 1, 'revision' => 1, 'version' => 'v1-fa2662c5628e',
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'published_at' => (new \DateTimeImmutable())->format('c'),
        ]);

    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_enemy');
        $this->addSql('DROP TABLE game_item');
        $this->addSql('DROP TABLE game_loot_table');
        $this->addSql('DROP TABLE game_ruleset');
        $this->addSql('DROP TABLE game_settings');
        $this->addSql('DROP TABLE game_title');
    }

    /** @param array<string, scalar|null> $values */
    private function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $values = array_map(static fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, $values);
        $this->addSql(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
            array_values($values),
        );
    }
}
