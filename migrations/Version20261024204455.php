<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Ajouts ciblés au publié et au brouillon (#319) : aucune édition en attente ni archive
 * n'est réécrite. weapon_type décrit seulement l'animation, hors empreinte de gameplay.
 * https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/migration-classes.html
 */
final class Version20261024204455 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fer play, Huilesson et familles d’animation des armes, sans perte de données (#319).';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $this->addSql('ALTER TABLE game_item ADD COLUMN IF NOT EXISTS weapon_type VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE game_item SET weapon_type = 'FIST' WHERE slot = 'WEAPON' AND weapon_type IS NULL");
        $source = file_get_contents(__DIR__.'/data/sports-weapons.json');
        \assert(false !== $source);
        /** @var list<array{key: string, slot: string, weapon_type: string, price_coins: int, sell_price_coins: int, modifiers: list<array{type: string, value: int}>, image_path: string, translations: array<string, array{name: string}>}> $definitions */
        $definitions = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        \assert(false !== $published);
        /** @var array{items: list<array<string, mixed>>, loot: array{version: int, chest: list<array{key: string, entries: list<array{item?: string, weight: int}>}>}} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        $before = $snapshot;
        foreach ($snapshot['items'] as &$item) {
            if ('WEAPON' === ($item['slot'] ?? null) && !isset($item['weapon_type'])) {
                $item['weapon_type'] = 'FIST';
            }
        }
        unset($item);
        $sort = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sort_order), -1) FROM game_item');
        foreach ($definitions as $item) {
            $this->addSql('INSERT INTO game_item (id, item_key, active, ever_published_active, sort_order, rarity, kind, slot, weapon_type, price_coins, sell_price_coins, modifiers, shop_available, shop_minimum_level, image_path, translations) VALUES (?, ?, true, true, ?, ?, ?, ?, ?, ?, ?, ?, true, 1, ?, ?) ON CONFLICT (item_key) DO NOTHING', [
                Uuid::v7()->toRfc4122(), $item['key'], ++$sort, 'COMMON', 'EQUIPMENT', $item['slot'], $item['weapon_type'],
                $item['price_coins'], $item['sell_price_coins'], json_encode($item['modifiers'], \JSON_THROW_ON_ERROR),
                $item['image_path'], json_encode($item['translations'], \JSON_THROW_ON_ERROR),
            ]);
            if (!\in_array($item['key'], array_column($snapshot['items'], 'key'), true)) {
                $snapshot['items'][] = $item + ['active' => true, 'rarity' => 'COMMON', 'kind' => 'EQUIPMENT', 'shop' => ['available' => true, 'minimum_level' => 1]];
            }
            $drop = json_encode([['item' => $item['key'], 'weight' => 5]], \JSON_THROW_ON_ERROR);
            $this->addSql("UPDATE game_loot_table SET entries = (entries::jsonb || ?::jsonb)::json WHERE table_kind = 'chest' AND table_key = 'WOODEN_CHEST' AND NOT EXISTS (SELECT 1 FROM jsonb_array_elements(entries::jsonb) entry WHERE entry->>'item' = ?)", [$drop, $item['key']]);
        }
        $lootChanged = false;
        $found = false;
        foreach ($snapshot['loot']['chest'] as &$chest) {
            if ('WOODEN_CHEST' !== $chest['key']) {
                continue;
            }
            $found = true;
            foreach ($definitions as $item) {
                if (!\in_array($item['key'], array_column($chest['entries'], 'item'), true)) {
                    $chest['entries'][] = ['item' => $item['key'], 'weight' => 5];
                    $lootChanged = true;
                }
            }
        }
        unset($chest);
        $this->abortIf(!$found, 'Le coffre en bois doit exister dans le snapshot publié.');
        if ($lootChanged) {
            ++$snapshot['loot']['version'];
            $this->addSql('UPDATE game_settings SET loot_version = loot_version + 1 WHERE id = 1');
        }
        if ($before === $snapshot) {
            return;
        }
        // Le calcul historique est conservé ; le nouveau champ de présentation en est exclu.
        $gameplay = $snapshot;
        foreach ($gameplay['items'] as &$item) {
            unset($item['weapon_type']);
        }
        unset($item);
        $version = AlamRulesetVersion::of($gameplay);
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $revision = (int) $published['revision'] + 1;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'sports-weapons-319', $version, $encoded]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les armes peuvent être possédées ou figées dans un combat ; conserver leurs clés et leur type.');
    }
}
