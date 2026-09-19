<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Ajoute une ressource et une recette provisoires sans modifier les équipements existants.
 * Le snapshot courant reçoit ce contenu initial ; les publications historiques restent intactes.
 */
final class Version20260914110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ressources, recettes publiables et audits de crafting/raid (#279).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_recipe (id UUID NOT NULL, recipe_key VARCHAR(64) NOT NULL, active BOOLEAN NOT NULL, ever_published_active BOOLEAN NOT NULL, sort_order INT NOT NULL, result_item VARCHAR(64) NOT NULL, quantity INT NOT NULL CHECK (quantity > 0), costs JSON NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_recipe_key ON game_recipe (recipe_key)');
        $this->addSql('CREATE TABLE rewards_crafting_audit (id UUID NOT NULL, user_id UUID NOT NULL, recipe_key VARCHAR(64) NOT NULL, result_key VARCHAR(64) NOT NULL, quantity INT NOT NULL CHECK (quantity > 0), costs JSON NOT NULL, ruleset_version VARCHAR(64) NOT NULL, crafted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE rewards_alam_grant (id UUID NOT NULL, run_id UUID NOT NULL, encounter INT NOT NULL, user_id UUID NOT NULL, items JSON NOT NULL, ruleset_version VARCHAR(64) NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_alam_grant_origin ON rewards_alam_grant (run_id, encounter, user_id)');
        $maximumOrder = $this->connection->fetchOne('SELECT COALESCE(MAX(sort_order), -1) FROM game_item');
        \assert(\is_int($maximumOrder) || \is_string($maximumOrder));
        $sortOrder = (int) $maximumOrder + 1;
        $translations = ['fr' => ['name' => 'Essence du Nafs'], 'en' => ['name' => 'Nafs Essence']];
        $this->addSql('INSERT INTO game_item (id, item_key, active, ever_published_active, sort_order, rarity, kind, slot, price_coins, sell_price_coins, modifiers, shop_available, shop_minimum_level, image_path, translations) VALUES (?, ?, true, true, ?, ?, ?, NULL, 0, 0, ?, false, NULL, ?, ?)', [Uuid::v7()->toRfc4122(), 'NAFS_ESSENCE', $sortOrder, 'COMMON', 'RESOURCE', '[]', 'placeholder.png', json_encode($translations, \JSON_THROW_ON_ERROR)]);
        $this->addSql('INSERT INTO game_recipe (id, recipe_key, active, ever_published_active, sort_order, result_item, quantity, costs) VALUES (?, ?, true, true, 0, ?, 1, ?)', [Uuid::v7()->toRfc4122(), 'IRON_GAUNTLETS', 'IRON_GAUNTLETS', json_encode([['item' => 'NAFS_ESSENCE', 'quantity' => 10]], \JSON_THROW_ON_ERROR)]);
        $stored = $this->connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
        \assert(\is_string($stored));
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($stored, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot['items']));
        $snapshot['items'][] = ['key' => 'NAFS_ESSENCE', 'active' => true, 'rarity' => 'COMMON', 'kind' => 'RESOURCE', 'price_coins' => 0, 'sell_price_coins' => 0, 'modifiers' => [], 'shop' => ['available' => false], 'image_path' => 'placeholder.png', 'translations' => $translations];
        $snapshot['recipes'] = [['key' => 'IRON_GAUNTLETS', 'active' => true, 'result_item' => 'IRON_GAUNTLETS', 'quantity' => 1, 'costs' => ['NAFS_ESSENCE' => 10]]];
        require_once __DIR__.'/CombatV2Version.php';
        $this->addSql('UPDATE game_ruleset SET revision = revision + 1, draft_revision = draft_revision + 1, version = ?, snapshot = ?, published_at = CURRENT_TIMESTAMP WHERE id = 1', [CombatV2Version::of($snapshot), json_encode($snapshot, \JSON_THROW_ON_ERROR)]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les ressources peuvent déjà appartenir à des joueurs et des raids figés.');
    }
}
