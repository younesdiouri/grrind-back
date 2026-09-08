<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Phase de développement : seule la timeline combat est jetable. Les UUID de causes
 * des loots/monnaies restent auditables ; aucun CASCADE et aucun effacement de progression.
 * Draft et snapshot publié sont convertis séparément : aucun brouillon n'est publié ici. */
final class Version20260908120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Combat v2 : synergies, fatigue, cadence ; purge ciblée des anciens combats (#269)';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/CombatV2Upgrade.php';
        require_once __DIR__.'/CombatV2Version.php';
        // Le fingerprint ne permet pas de retrouver la route. Vérifier forme, UUID et
        // propriétaire contre le combat réel évite d'effacer d'autres réponses idempotentes.
        foreach ($this->connection->iterateAssociative('SELECT id, user_id, response_body FROM shared_idempotency_key WHERE response_status = 201 AND response_body IS NOT NULL') as $record) {
            \assert(\is_string($record['response_body']));
            $body = json_decode($record['response_body'], true);
            if (!\is_array($body) || !\is_string($body['id'] ?? null) || !isset($body['player'], $body['enemy'], $body['events'])) {
                continue;
            }
            if (false !== $this->connection->fetchOne('SELECT id FROM combat_battle WHERE id::text = ? AND player_id = ?', [$body['id'], $record['user_id']])) {
                $this->addSql('DELETE FROM shared_idempotency_key WHERE id = ?', [$record['id']]);
            }
        }
        $this->addSql('DELETE FROM combat_battle');
        $this->addSql('ALTER TABLE combat_battle RENAME turns TO attack_count');
        $this->addSql('ALTER TABLE combat_battle ADD action_count INT NOT NULL, ADD elapsed_ticks INT NOT NULL, ADD end_reason VARCHAR(16) NOT NULL, ADD algorithm_version VARCHAR(8) NOT NULL');
        $this->addSql('ALTER TABLE game_enemy RENAME extra_turn_permille TO combo_permille');
        foreach (['maintenance', 'critical_chance', 'guard', 'critical_resistance', 'cooldown_reduction', 'precision'] as $name) {
            $this->addSql(sprintf('ALTER TABLE game_enemy ADD %s_permille INT NOT NULL DEFAULT 0', $name));
            $this->addSql(sprintf('ALTER TABLE game_enemy ALTER %s_permille DROP DEFAULT', $name));
        }
        $this->addSql(<<<'SQL'
            UPDATE game_item SET modifiers = (
                SELECT COALESCE(jsonb_agg(CASE WHEN modifier->>'type' = 'EXTRA_TURN_BONUS'
                    THEN jsonb_set(modifier, '{type}', '"COMBO_BONUS"'::jsonb) ELSE modifier END ORDER BY position), '[]'::jsonb)
                FROM jsonb_array_elements(modifiers::jsonb) WITH ORDINALITY AS m(modifier, position)
            )
            SQL);
        $stored = $this->connection->fetchOne('SELECT fighter FROM game_settings WHERE id = 1');
        \assert(\is_string($stored));
        /** @var array<string, mixed> $fighter */
        $fighter = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        $this->addSql('UPDATE game_settings SET fighter = ? WHERE id = 1', [json_encode(CombatV2Upgrade::fighter($fighter), JSON_THROW_ON_ERROR)]);
        $stored = $this->connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
        \assert(\is_string($stored));
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        $snapshot = CombatV2Upgrade::snapshot($snapshot);
        $this->addSql('UPDATE game_ruleset SET revision = revision + 1, version = ?, snapshot = ?, published_at = CURRENT_TIMESTAMP WHERE id = 1', [CombatV2Version::of($snapshot), json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les combats v1 supprimés ne peuvent pas être reconstruits.');
    }
}
