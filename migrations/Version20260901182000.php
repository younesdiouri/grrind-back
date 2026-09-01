<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Admin\Infrastructure\GameRulesetSeed;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/** Répare les environnements qui avaient reçu la première ébauche de #260 sans seed. */
final class Version20260901182000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Complète de façon idempotente le ruleset administrable initial (#260)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_settings ADD COLUMN IF NOT EXISTS loot_version INT NOT NULL DEFAULT 1');
        // La première ébauche de #260 publiait le marqueur transitoire `v1-seeded`.
        // La même empreinte hybride que le seed immuable le remplace sans toucher au
        // snapshot ni aux faits déjà écrits.
        $this->addSql("UPDATE game_ruleset SET version = 'v1-64dbac7efb84' WHERE id = 1 AND version = 'v1-seeded'");
        if (false !== $this->connection->fetchOne('SELECT 1 FROM game_ruleset WHERE id = 1')) {
            return;
        }

        $seed = GameRulesetSeed::data();
        /** @var list<array<string, mixed>> $items */ $items = $seed['items'];
        /** @var list<array<string, mixed>> $titles */ $titles = $seed['titles'];
        /** @var list<array<string, mixed>> $enemies */ $enemies = [...$seed['enemies'], ...$seed['bosses']];
        /** @var array<string, mixed> $loot */ $loot = $seed['loot'];
        /** @var array<string, int> $fighter */ $fighter = $seed['fighter'];

        foreach ($items as $item) {
            $this->insert('game_item', ['id' => Uuid::v7()->toRfc4122(), 'item_key' => $item['key'], 'active' => true, 'sort_order' => $item['sort_order'], 'rarity' => $item['rarity'], 'kind' => $item['kind'] ?? 'EQUIPMENT', 'slot' => $item['slot'] ?? null, 'price_coins' => $item['price_coins'], 'modifiers' => json_encode($item['modifiers'] ?? [], JSON_THROW_ON_ERROR), 'shop_available' => $item['shop']['available'] ?? false, 'shop_minimum_level' => $item['shop']['minimum_level'] ?? null, 'image_path' => $item['image_path'], 'translations' => json_encode($item['translations'], JSON_THROW_ON_ERROR)]);
        }
        foreach ($titles as $title) {
            $this->insert('game_title', ['id' => Uuid::v7()->toRfc4122(), 'title_key' => $title['id'], 'active' => true, 'sort_order' => $title['sort_order'], 'condition_type' => $title['condition']['type'], 'threshold' => $title['condition']['threshold'], 'discipline' => $title['condition']['discipline'] ?? null, 'translations' => json_encode($title['translations'], JSON_THROW_ON_ERROR)]);
        }
        foreach ($enemies as $enemy) {
            $this->insert('game_enemy', ['id' => Uuid::v7()->toRfc4122(), 'enemy_key' => $enemy['key'], 'active' => true, 'sort_order' => $enemy['sort_order'], 'boss' => $enemy['boss'], 'minimum_level' => $enemy['minimum_level'], 'hp' => $enemy['hp'], 'damage' => $enemy['damage'], 'mitigation_permille' => $enemy['mitigation_permille'], 'extra_turn_permille' => $enemy['extra_turn_permille'], 'dodge_permille' => $enemy['dodge_permille'], 'translations' => json_encode($enemy['translations'], JSON_THROW_ON_ERROR)]);
        }
        foreach (['workout', 'adversary', 'chest'] as $kind) {
            /** @var list<array<string, mixed>> $tables */ $tables = $loot[$kind];
            foreach ($tables as $sortOrder => $table) {
                $this->insert('game_loot_table', ['id' => Uuid::v7()->toRfc4122(), 'table_kind' => $kind, 'table_key' => $table['key'], 'active' => true, 'sort_order' => $sortOrder, 'eligibility' => isset($table['eligibility']) ? json_encode($table['eligibility'], JSON_THROW_ON_ERROR) : null, 'coins_minimum' => $table['coins']['minimum'], 'coins_maximum' => $table['coins']['maximum'], 'entries' => json_encode($table['entries'], JSON_THROW_ON_ERROR)]);
            }
        }
        /** @var array{floor_percent: int, cap_percent: int} $luck */ $luck = $loot['loot_luck'];
        $this->insert('game_settings', ['id' => 1, 'fighter' => json_encode($fighter, JSON_THROW_ON_ERROR), 'loot_luck' => json_encode($luck, JSON_THROW_ON_ERROR), 'loot_version' => 1]);
        $snapshot = ['items' => $items, 'titles' => $titles, 'combat' => ['fighter' => $fighter, 'enemies' => $seed['enemies'], 'bosses' => $seed['bosses']], 'loot' => ['version' => 1, ...$loot]];
        $this->insert('game_ruleset', ['id' => 1, 'revision' => 1, 'version' => 'v1-64dbac7efb84', 'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'published_at' => (new \DateTimeImmutable())->format('c')]);
    }

    public function down(Schema $schema): void
    {
        // Une correction idempotente ne supprime jamais les configurations administrées.
    }

    /** @param array<string, scalar|null> $values */
    private function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $values = array_map(static fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, $values);
        $this->addSql(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))), array_values($values));
    }
}
