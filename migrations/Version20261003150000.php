<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Le streak (#286) : le réglage publié `streak`, l'audit des coffres attribués, et les deux
 * coffres qui manquaient — Épique et Légendaire.
 *
 * **Leurs tables sont provisoires**, calquées sur les deux coffres existants avec les objets
 * de leur rareté : l'équilibrage des objets et des taux de drop fera l'objet d'un document à
 * part. Elles vivent dans l'admin pour être corrigées sans déploiement. Ni l'un ni l'autre
 * n'est en boutique : la régularité est leur seule source.
 */
final class Version20261003150000 extends AbstractMigration
{
    private const array STREAK = ['minimum_daily_seconds' => 1800, 'chests' => ['COMMON' => 'WOODEN_CHEST', 'RARE' => 'IRON_BOUND_CHEST', 'EPIC' => 'GILDED_CHEST', 'LEGENDARY' => 'ANCESTRAL_CHEST']];

    private const array CHESTS = [
        ['key' => 'GILDED_CHEST', 'rarity' => 'EPIC', 'price_coins' => 400, 'translations' => ['fr' => ['name' => 'Coffre doré'], 'en' => ['name' => 'Gilded Chest']], 'coins' => ['minimum' => 90, 'maximum' => 180], 'entries' => [['weight' => 30], ['item' => 'OBSIDIAN_WARBLADE', 'weight' => 35], ['item' => 'STORMCALLERS_BOOTS', 'weight' => 35]]],
        ['key' => 'ANCESTRAL_CHEST', 'rarity' => 'LEGENDARY', 'price_coins' => 900, 'translations' => ['fr' => ['name' => 'Coffre ancestral'], 'en' => ['name' => 'Ancestral Chest']], 'coins' => ['minimum' => 180, 'maximum' => 360], 'entries' => [['weight' => 20], ['item' => 'CROWN_OF_THE_TIRELESS', 'weight' => 50], ['item' => 'OBSIDIAN_WARBLADE', 'weight' => 15], ['item' => 'STORMCALLERS_BOOTS', 'weight' => 15]]],
    ];

    public function getDescription(): string
    {
        return 'Streak : réglage publié, audit des coffres, coffres Épique et Légendaire (#286).';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $this->addSql('CREATE TABLE engagement_streak_chest (id UUID NOT NULL, user_id UUID NOT NULL, run_started_on DATE NOT NULL, week INT NOT NULL, rarity VARCHAR(20) NOT NULL, item_key VARCHAR(64) NOT NULL, ruleset_version VARCHAR(64) NOT NULL, granted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_streak_chest_week ON engagement_streak_chest (user_id, run_started_on, week)');
        $this->addSql("ALTER TABLE game_settings ADD streak JSON NOT NULL DEFAULT '{}'::json");
        $this->addSql('ALTER TABLE game_settings ALTER streak DROP DEFAULT');
        $this->addSql('UPDATE game_settings SET streak = ? WHERE id = 1', [json_encode(self::STREAK, \JSON_THROW_ON_ERROR)]);

        $itemOrder = $this->connection->fetchOne('SELECT COALESCE(MAX(sort_order), -1) FROM game_item');
        $tableOrder = $this->connection->fetchOne("SELECT COALESCE(MAX(sort_order), -1) FROM game_loot_table WHERE table_kind = 'chest'");
        \assert((\is_int($itemOrder) || \is_string($itemOrder)) && (\is_int($tableOrder) || \is_string($tableOrder)));
        $stored = $this->connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
        /** @var array{items: list<array<string, mixed>>, loot: array{chest: list<array<string, mixed>>}}|null $snapshot */
        $snapshot = \is_string($stored) ? json_decode($stored, true, 512, \JSON_THROW_ON_ERROR) : null;

        foreach (self::CHESTS as $offset => $chest) {
            $translations = json_encode($chest['translations'], \JSON_THROW_ON_ERROR);
            $this->addSql('INSERT INTO game_item (id, item_key, active, ever_published_active, sort_order, rarity, kind, slot, price_coins, sell_price_coins, modifiers, shop_available, shop_minimum_level, image_path, translations) VALUES (?, ?, true, true, ?, ?, ?, NULL, ?, 0, ?, false, NULL, ?, ?)', [Uuid::v7()->toRfc4122(), $chest['key'], (int) $itemOrder + 1 + $offset, $chest['rarity'], 'CHEST', $chest['price_coins'], '[]', 'placeholder.png', $translations]);
            $this->addSql('INSERT INTO game_loot_table (id, table_kind, table_key, active, ever_published_active, sort_order, eligibility, coins_minimum, coins_maximum, entries) VALUES (?, ?, ?, true, true, ?, NULL, ?, ?, ?)', [Uuid::v7()->toRfc4122(), 'chest', $chest['key'], (int) $tableOrder + 1 + $offset, $chest['coins']['minimum'], $chest['coins']['maximum'], json_encode($chest['entries'], \JSON_THROW_ON_ERROR)]);
            if (null !== $snapshot) {
                $snapshot['items'][] = ['key' => $chest['key'], 'active' => true, 'rarity' => $chest['rarity'], 'kind' => 'CHEST', 'slot' => null, 'price_coins' => $chest['price_coins'], 'sell_price_coins' => 0, 'modifiers' => [], 'shop' => ['available' => false, 'minimum_level' => null], 'image_path' => 'placeholder.png', 'translations' => $chest['translations']];
                $snapshot['loot']['chest'][] = ['key' => $chest['key'], 'active' => true, 'coins' => $chest['coins'], 'entries' => $chest['entries']];
            }
        }

        if (null !== $snapshot) {
            $snapshot['streak'] = self::STREAK;
            $this->addSql('UPDATE game_ruleset SET revision = revision + 1, draft_revision = draft_revision + 1, version = ?, snapshot = ?, published_at = CURRENT_TIMESTAMP WHERE id = 1', [AlamRulesetVersion::of($snapshot), json_encode($snapshot, \JSON_THROW_ON_ERROR)]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Des coffres de streak ont pu être attribués et ouverts.');
    }
}
