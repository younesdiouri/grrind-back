<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

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
        $this->addSql('CREATE TABLE game_enemy (id UUID NOT NULL, enemy_key VARCHAR(100) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL, boss BOOLEAN NOT NULL, minimum_level INT NOT NULL, hp INT NOT NULL, damage INT NOT NULL, mitigation_permille INT NOT NULL, extra_turn_permille INT NOT NULL, dodge_permille INT NOT NULL, translations JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_enemy_key ON game_enemy (enemy_key)');
        $this->addSql('CREATE TABLE game_item (id UUID NOT NULL, item_key VARCHAR(100) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL, rarity VARCHAR(20) NOT NULL, kind VARCHAR(20) NOT NULL, slot VARCHAR(30) DEFAULT NULL, price_coins INT NOT NULL, modifiers JSON NOT NULL, shop_available BOOLEAN NOT NULL, shop_minimum_level INT DEFAULT NULL, image_path VARCHAR(255) NOT NULL, translations JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_item_key ON game_item (item_key)');
        $this->addSql('CREATE TABLE game_loot_table (id UUID NOT NULL, table_kind VARCHAR(20) NOT NULL, table_key VARCHAR(100) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL, eligibility JSON DEFAULT NULL, coins_minimum INT NOT NULL, coins_maximum INT NOT NULL, entries JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_loot_table_kind_key ON game_loot_table (table_kind, table_key)');
        $this->addSql('CREATE TABLE game_ruleset (id INT NOT NULL, revision INT NOT NULL, version VARCHAR(32) NOT NULL, snapshot JSON NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE game_settings (id INT NOT NULL, fighter JSON NOT NULL, loot_luck JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE game_title (id UUID NOT NULL, title_key VARCHAR(100) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL, condition_type VARCHAR(40) NOT NULL, threshold INT NOT NULL, discipline VARCHAR(30) DEFAULT NULL, translations JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_title_key ON game_title (title_key)');
        $directory = dirname(__DIR__).'/config/game/v1';
        /** @var array{items: list<array<string, mixed>>} $items */
        $items = Yaml::parseFile($directory.'/items.yaml');
        /** @var array{titles: list<array<string, mixed>>} $titles */
        $titles = Yaml::parseFile($directory.'/titles.yaml');
        /** @var array{fighter: array<string, int>, enemies: list<array<string, mixed>>, bosses: list<array<string, mixed>>} $combat */
        $combat = Yaml::parseFile($directory.'/combat.yaml');
        /** @var array{loot_luck: array{floor_percent: int, cap_percent: int}, workout: list<array<string, mixed>>, adversary: list<array<string, mixed>>, chest: list<array<string, mixed>>} $loot */
        $loot = Yaml::parseFile($directory.'/loot.yaml');
        $itemFr = Yaml::parseFile(dirname(__DIR__).'/translations/items.fr.yaml');
        $itemEn = Yaml::parseFile(dirname(__DIR__).'/translations/items.en.yaml');
        $titleFr = Yaml::parseFile(dirname(__DIR__).'/translations/titles.fr.yaml');
        $titleEn = Yaml::parseFile(dirname(__DIR__).'/translations/titles.en.yaml');
        $enemyFr = Yaml::parseFile(dirname(__DIR__).'/translations/enemies.fr.yaml');
        $enemyEn = Yaml::parseFile(dirname(__DIR__).'/translations/enemies.en.yaml');

        foreach ($items['items'] as $position => $item) {
            $key = (string) $item['key'];
            $translations = ['fr' => ['name' => $itemFr[strtolower($key).'.name']], 'en' => ['name' => $itemEn[strtolower($key).'.name']]];
            $this->insert('game_item', [
                'id' => Uuid::v7()->toRfc4122(), 'item_key' => $key, 'active' => true, 'sort_order' => $position,
                'rarity' => $item['rarity'], 'kind' => $item['kind'] ?? 'EQUIPMENT', 'slot' => $item['slot'] ?? null,
                'price_coins' => $item['price_coins'], 'modifiers' => json_encode($item['modifiers'] ?? [], JSON_THROW_ON_ERROR),
                'shop_available' => $item['shop']['available'] ?? false, 'shop_minimum_level' => $item['shop']['minimum_level'] ?? null,
                'image_path' => 'placeholder.png', 'translations' => json_encode($translations, JSON_THROW_ON_ERROR),
            ]);
        }
        foreach ($titles['titles'] as $position => $title) {
            $key = (string) $title['id'];
            $translations = ['fr' => ['name' => $titleFr[$key.'.name'], 'hint' => $titleFr[$key.'.hint']], 'en' => ['name' => $titleEn[$key.'.name'], 'hint' => $titleEn[$key.'.hint']]];
            $this->insert('game_title', ['id' => Uuid::v7()->toRfc4122(), 'title_key' => $key, 'active' => true, 'sort_order' => $position, 'condition_type' => $title['condition']['type'], 'threshold' => $title['condition']['threshold'], 'discipline' => $title['condition']['discipline'] ?? null, 'translations' => json_encode($translations, JSON_THROW_ON_ERROR)]);
        }
        foreach ([false => $combat['enemies'], true => $combat['bosses']] as $boss => $entries) {
            foreach ($entries as $position => $enemy) {
                $key = (string) $enemy['key'];
                $translations = ['fr' => ['name' => $enemyFr[strtolower($key).'.name']], 'en' => ['name' => $enemyEn[strtolower($key).'.name']]];
                $this->insert('game_enemy', ['id' => Uuid::v7()->toRfc4122(), 'enemy_key' => $key, 'active' => true, 'sort_order' => $position, 'boss' => $boss, 'minimum_level' => $enemy[$boss ? 'minimum_level' : 'level'], 'hp' => $enemy['hp'], 'damage' => $enemy['damage'], 'mitigation_permille' => $enemy['mitigation_permille'], 'extra_turn_permille' => $enemy['extra_turn_permille'], 'dodge_permille' => $enemy['dodge_permille'], 'translations' => json_encode($translations, JSON_THROW_ON_ERROR)]);
            }
        }
        foreach (['workout', 'adversary', 'chest'] as $kind) {
            foreach ($loot[$kind] as $position => $table) {
                $this->insert('game_loot_table', ['id' => Uuid::v7()->toRfc4122(), 'table_kind' => $kind, 'table_key' => $table['key'], 'active' => true, 'sort_order' => $position, 'eligibility' => isset($table['eligibility']) ? json_encode($table['eligibility'], JSON_THROW_ON_ERROR) : null, 'coins_minimum' => $table['coins']['minimum'], 'coins_maximum' => $table['coins']['maximum'], 'entries' => json_encode($table['entries'], JSON_THROW_ON_ERROR)]);
            }
        }
        $this->insert('game_settings', ['id' => 1, 'fighter' => json_encode($combat['fighter'], JSON_THROW_ON_ERROR), 'loot_luck' => json_encode($loot['loot_luck'], JSON_THROW_ON_ERROR)]);
        $snapshot = ['items' => $items['items'], 'titles' => $titles['titles'], 'combat' => $combat, 'loot' => $loot];
        $version = 'v1-'.substr(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), 0, 12);
        $this->insert('game_ruleset', ['id' => 1, 'revision' => 1, 'version' => $version, 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'published_at' => (new \DateTimeImmutable())->format('c')]);

        $placeholder = dirname(__DIR__).'/var/game-images/placeholder.png';
        if (!is_dir(dirname($placeholder))) { mkdir(dirname($placeholder), 0775, true); }
        if (!is_file($placeholder)) { file_put_contents($placeholder, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL8ywAAAABJRU5ErkJggg==', true)); }
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
