<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Domain\GameEnemy;
use App\Admin\Domain\GameItem;
use App\Admin\Domain\GameLootTable;
use App\Admin\Domain\GameTitle;
use Doctrine\DBAL\Connection;
use LogicException;

/**
 * La suppression physique ne vaut que pour une donnée jamais publiée ni utilisée. Les
 * snapshots gardent le détail des faits, mais les tables historiques indexées restent la
 * garde rapide et explicable du back-office.
 */
final readonly class GameConfigurationReferenceGuard
{
    public function __construct(private Connection $connection)
    {
    }

    public function assertDeletable(object $configuration): void
    {
        $active = match (true) {
            $configuration instanceof GameItem, $configuration instanceof GameTitle, $configuration instanceof GameEnemy, $configuration instanceof GameLootTable => $configuration->isActive(),
            default => false,
        };
        if ($active) {
            throw new LogicException('Suppression refusée : désactivez d’abord cette configuration publiée.');
        }
        [$label, $key, $queries] = match (true) {
            $configuration instanceof GameItem => ['item', $configuration->getKey(), [
                ['SELECT 1 FROM rewards_inventory_item WHERE item_key = ? LIMIT 1', [$configuration->getKey()]],
                ['SELECT 1 FROM game_loot_table WHERE entries::jsonb @> ?::jsonb LIMIT 1', [json_encode([['item' => $configuration->getKey()]], \JSON_THROW_ON_ERROR)]],
                ["SELECT 1 FROM rewards_loot_roll WHERE jsonb_exists(result->'items', ?) LIMIT 1", [$configuration->getKey()]],
                ['SELECT 1 FROM combat_battle WHERE reward @> ?::jsonb LIMIT 1', [json_encode(['loot' => [['key' => $configuration->getKey()]]], \JSON_THROW_ON_ERROR)]],
            ]],
            $configuration instanceof GameTitle => ['titre', $configuration->getKey(), [
                ['SELECT 1 FROM player_title WHERE title_id = ? LIMIT 1', [$configuration->getKey()]],
                ['SELECT 1 FROM player_active_title WHERE title_id = ? LIMIT 1', [$configuration->getKey()]],
            ]],
            $configuration instanceof GameEnemy => ['ennemi', $configuration->getKey(), [
                ["SELECT 1 FROM combat_battle WHERE enemy_snapshot->>'key' = ? LIMIT 1", [$configuration->getKey()]],
                ['SELECT 1 FROM game_loot_table WHERE table_kind = ? AND table_key = ? LIMIT 1', ['adversary', $configuration->getKey()]],
            ]],
            $configuration instanceof GameLootTable => ['table de loot', $configuration->getKind().':'.$configuration->getKey(), [
                ['SELECT 1 FROM rewards_loot_roll WHERE table_key = ? LIMIT 1', [$configuration->getKey()]],
            ]],
            default => ['', '', []],
        };
        foreach ($queries as [$sql, $parameters]) {
            if (false !== $this->connection->fetchOne($sql, $parameters)) {
                throw new LogicException(\sprintf('Suppression refusée : %s « %s » est référencé par des données de jeu ou historiques.', $label, $key));
            }
        }
    }
}
