<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Bandeau éclair (#325) : ajout ciblé au publié et au brouillon, comme #319. Cosmétique pur,
 * hors coffre ; aucune édition en attente, possession ni archive n'est réécrite.
 */
final class Version20261027090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bandeau éclair en boutique à 1 pièce d’or, sans perte de données (#325).';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $source = file_get_contents(__DIR__.'/data/bandeau-eclair.json');
        \assert(false !== $source);
        /** @var list<array{key: string, slot: string, price_coins: int, sell_price_coins: int, modifiers: list<array{type: string, value: int}>, image_path: string, translations: array<string, array{name: string}>}> $definitions */
        $definitions = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        \assert(false !== $published);
        /** @var array{items: list<array<string, mixed>>} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        $before = $snapshot;
        $sort = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sort_order), -1) FROM game_item');
        foreach ($definitions as $item) {
            $this->addSql('INSERT INTO game_item (id, item_key, active, ever_published_active, sort_order, rarity, kind, slot, weapon_type, price_coins, sell_price_coins, modifiers, shop_available, shop_minimum_level, image_path, translations) VALUES (?, ?, true, true, ?, ?, ?, ?, NULL, ?, ?, ?, true, 1, ?, ?) ON CONFLICT (item_key) DO NOTHING', [
                Uuid::v7()->toRfc4122(), $item['key'], ++$sort, 'COMMON', 'EQUIPMENT', $item['slot'],
                $item['price_coins'], $item['sell_price_coins'], json_encode($item['modifiers'], \JSON_THROW_ON_ERROR),
                $item['image_path'], json_encode($item['translations'], \JSON_THROW_ON_ERROR),
            ]);
            if (!\in_array($item['key'], array_column($snapshot['items'], 'key'), true)) {
                $snapshot['items'][] = $item + ['active' => true, 'rarity' => 'COMMON', 'kind' => 'EQUIPMENT', 'shop' => ['available' => true, 'minimum_level' => 1]];
            }
        }
        if ($before === $snapshot) {
            return;
        }
        // Même empreinte que #319 : weapon_type, présentation seule, en est exclu.
        $gameplay = $snapshot;
        foreach ($gameplay['items'] as &$item) {
            unset($item['weapon_type']);
        }
        unset($item);
        $version = AlamRulesetVersion::of($gameplay);
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $revision = (int) $published['revision'] + 1;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'bandeau-eclair-325', $version, $encoded]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Le bandeau peut être possédé ou figé dans un combat ; conserver sa clé.');
    }
}
