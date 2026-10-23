<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Sept équipements Unity accessibles (#313), sans bonus d'attribut brut ni faux pack 2D.
 * Le publié et le brouillon reçoivent uniquement ces ajouts : aucune édition en attente
 * n'est publiée. Les icônes se provisionnent séparément dans le volume game-images.
 *
 * Migration de données transactionnelle : https://symfony.com/doc/current/doctrine.html
 * et https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/migration-classes.html.
 */
final class Version20261023180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sept équipements Unity COMMON, niveau 1, bonus de taux ou dégâts, boutique et coffre en bois (#313).';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $source = file_get_contents(__DIR__.'/data/unity-equipment.json');
        \assert(false !== $source);
        /** @var list<array{key: string, slot: string, price_coins: int, sell_price_coins: int, modifiers: list<array{type: string, value: int}>, image_path: string, translations: array<string, array{name: string}>}> $items */
        $items = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        \assert(false !== $published);
        /** @var array{items: list<array<string, mixed>>, loot: array{version: int, chest: list<array{key: string, entries: list<array{item?: string, weight: int}>}>}} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        $sort = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sort_order), -1) FROM game_item');
        $drops = [];
        foreach ($items as $item) {
            $this->addSql('INSERT INTO game_item (id, item_key, active, ever_published_active, sort_order, rarity, kind, slot, price_coins, sell_price_coins, modifiers, shop_available, shop_minimum_level, image_path, translations) VALUES (?, ?, true, true, ?, ?, ?, ?, ?, ?, ?, true, 1, ?, ?)', [
                Uuid::v7()->toRfc4122(), $item['key'], ++$sort, 'COMMON', 'EQUIPMENT', $item['slot'],
                $item['price_coins'], $item['sell_price_coins'], json_encode($item['modifiers'], \JSON_THROW_ON_ERROR),
                $item['image_path'], json_encode($item['translations'], \JSON_THROW_ON_ERROR),
            ]);
            $snapshot['items'][] = $item + ['active' => true, 'rarity' => 'COMMON', 'kind' => 'EQUIPMENT', 'shop' => ['available' => true, 'minimum_level' => 1]];
            $drops[] = ['item' => $item['key'], 'weight' => 5];
        }
        $found = false;
        foreach ($snapshot['loot']['chest'] as &$chest) {
            if ('WOODEN_CHEST' === $chest['key']) {
                $chest['entries'] = [...$chest['entries'], ...$drops];
                $found = true;
            }
        }
        unset($chest);
        $this->abortIf(!$found, 'Le coffre en bois doit exister dans le snapshot publié.');
        // Chaque version garde ses poids et ses pièces ; le brouillon ne remplace jamais le publié.
        $this->addSql("UPDATE game_loot_table SET entries = (entries::jsonb || ?::jsonb)::json WHERE table_kind = 'chest' AND table_key = 'WOODEN_CHEST'", [json_encode($drops, \JSON_THROW_ON_ERROR)]);
        $this->addSql('UPDATE game_settings SET loot_version = loot_version + 1 WHERE id = 1');
        ++$snapshot['loot']['version'];
        $version = AlamRulesetVersion::of($snapshot);
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $revision = (int) $published['revision'] + 1;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'unity-equipment-313', $version, $encoded]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les équipements ont pu être acquis ; conserver leurs clés historiques.');
    }
}
