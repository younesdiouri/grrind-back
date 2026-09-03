<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure;

use App\Admin\Domain\GameActivityType;
use App\Admin\Domain\GameDiscipline;
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
        $state = $this->lockCurrentState($configuration);
        if ($state['active']) {
            throw new LogicException('Suppression refusée : désactivez d’abord cette configuration publiée.');
        }
        if ($state['everPublishedActive']) {
            // Une opération démarrée sous une ancienne révision peut encore écrire son fait.
            // Garder sa clé après désactivation ferme cette course sans imposer de verrou à
            // chaque lecture historique (inventaire, loot et bataille).
            throw new LogicException('Suppression refusée : cette configuration a déjà été publiée active et peut être référencée par une opération en cours.');
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
            $configuration instanceof GameDiscipline => ['discipline', $configuration->getDiscipline()->value, [
                ['SELECT 1 FROM game_title WHERE active = true AND discipline = ? LIMIT 1', [$configuration->getDiscipline()->value]],
                ['SELECT 1 FROM game_loot_table WHERE active = true AND eligibility::jsonb @> ?::jsonb LIMIT 1', [json_encode(['disciplines' => [$configuration->getDiscipline()->value]], \JSON_THROW_ON_ERROR)]],
                ['SELECT 1 FROM community_risala WHERE discipline = ? LIMIT 1', [$configuration->getDiscipline()->value]],
            ]],
            $configuration instanceof GameActivityType => ['type d’activité', $configuration->getProviderType(), []],
            default => ['', '', []],
        };
        foreach ($queries as [$sql, $parameters]) {
            if (false !== $this->connection->fetchOne($sql, $parameters)) {
                throw new LogicException(\sprintf('Suppression refusée : %s « %s » est référencé par des données de jeu ou historiques.', $label, $key));
            }
        }
    }

    /**
     * Prend le même verrou de ligne qu'une mutation avant son flush. Un objet Doctrine peut
     * être ancien : lire son booléen hydraté après l'attente d'un DELETE ne ferme pas la
     * course activation/publication -> suppression. La lecture verrouillée devient donc la
     * décision, et non un simple contrôle d'interface.
     */
    public function lockForMutation(object $configuration): void
    {
        if (!$configuration instanceof GameItem && !$configuration instanceof GameTitle && !$configuration instanceof GameEnemy && !$configuration instanceof GameLootTable && !$configuration instanceof GameDiscipline && !$configuration instanceof GameActivityType) {
            return;
        }
        $state = $this->lockCurrentState($configuration);
        if ($configuration instanceof GameDiscipline && $state['active'] && !$configuration->isActive()) {
            $currentRisala = $this->connection->fetchOne(
                "SELECT 1 FROM community_risala WHERE discipline = ? AND (status = 'DRAWN' OR (status = 'SENT' AND revealed_at <= CURRENT_TIMESTAMP AND expires_at > CURRENT_TIMESTAMP)) LIMIT 1",
                [$configuration->getDiscipline()->value],
            );
            if (false !== $currentRisala) {
                throw new LogicException('Désactivation refusée : cette discipline porte une Risāla en cours.');
            }
        }
    }

    /** @return array{active: bool, everPublishedActive: bool} */
    private function lockCurrentState(object $configuration): array
    {
        [$table, $id] = match (true) {
            $configuration instanceof GameItem => ['game_item', $configuration->getId()->toRfc4122()],
            $configuration instanceof GameTitle => ['game_title', $configuration->getId()->toRfc4122()],
            $configuration instanceof GameEnemy => ['game_enemy', $configuration->getId()->toRfc4122()],
            $configuration instanceof GameLootTable => ['game_loot_table', $configuration->getId()->toRfc4122()],
            $configuration instanceof GameDiscipline => ['game_discipline', $configuration->getId()->toRfc4122()],
            $configuration instanceof GameActivityType => ['game_activity_type', $configuration->getId()->toRfc4122()],
            default => throw new LogicException('Cette configuration ne peut pas être supprimée.'),
        };
        $everPublished = $configuration instanceof GameActivityType ? 'false AS ever_published_active' : 'ever_published_active';
        $state = $this->connection->fetchAssociative("SELECT active, {$everPublished} FROM {$table} WHERE id = ? FOR UPDATE", [$id]);
        if (false === $state) {
            // Si l'administrateur a attendu un DELETE concurrent, son UPDATE ne doit jamais
            // publier l'ancienne entité encore en mémoire comme si elle existait toujours.
            throw new LogicException('Cette configuration a été supprimée par une autre opération. Actualisez la page avant de réessayer.');
        }

        return [
            'active' => $this->databaseBoolean($state['active'] ?? false),
            'everPublishedActive' => $this->databaseBoolean($state['ever_published_active'] ?? false),
        ];
    }

    private function databaseBoolean(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value || 'true' === $value;
    }
}
