<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #329 : pilote avec seuils provisoires. Les anciennes séances ne conservent pas leur sport
 * précis ; pas de reconstruction depuis la discipline ni de modification de leurs crédits.
 */
final class Version20261028192407 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sport ensemble : preuves temporaires et compléments audités (#329).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE location_evidence (user_id UUID NOT NULL, batch_id UUID NOT NULL, source VARCHAR(32) NOT NULL, external_id VARCHAR(128) NOT NULL, origin VARCHAR(32) NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, points JSON NOT NULL, digest VARCHAR(64) NOT NULL, PRIMARY KEY (user_id, batch_id))');
        $this->addSql('CREATE INDEX idx_evidence_provider ON location_evidence (user_id, source, external_id)');
        $this->addSql('CREATE INDEX idx_evidence_expiry ON location_evidence (ended_at)');
        $this->addSql('CREATE TABLE together_bonus_receipt (id UUID NOT NULL, user_id UUID NOT NULL, workout_id UUID NOT NULL, multiplier INT NOT NULL, payload JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_together_receipt_user_id ON together_bonus_receipt (user_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_together_workout_multiplier ON together_bonus_receipt (workout_id, multiplier)');
        $this->addSql('ALTER TABLE game_activity_type ADD normalized_sport VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE game_settings ADD together JSON NOT NULL DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE workout ADD normalized_sport VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD together JSON NOT NULL DEFAULT \'{"status":"UNAVAILABLE","playerCount":1,"multiplier":1,"soloXp":0,"bonusXp":0,"totalXp":0,"rulesetVersion":null}\'');
        $this->addSql('ALTER TABLE workout ADD together_proof JSON NOT NULL DEFAULT \'{}\'');
        $this->addSql('ALTER TABLE xp_transaction ADD attribute_percentages JSON NOT NULL DEFAULT \'{}\'');
        $this->addSql('CREATE INDEX idx_workout_sport_started ON workout (normalized_sport, started_at)');
        $this->seed();
    }

    private function seed(): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $settings = ['maximum_players' => 4, 'overlap_percent' => 80, 'distance_meters' => 100, 'coverage_percent' => 80, 'maximum_gap_seconds' => 60, 'maximum_accuracy_meters' => 50, 'sample_seconds' => 15];
        $this->addSql('UPDATE game_settings SET together = ?', [json_encode($settings, \JSON_THROW_ON_ERROR)]);
        $this->addSql("UPDATE workout w SET together = json_build_object('status', 'UNAVAILABLE', 'playerCount', 1, 'multiplier', 1, 'soloXp', COALESCE((SELECT amount FROM xp_transaction WHERE source_id = w.id AND reason = 'SESSION_COMPLETED'), 0), 'bonusXp', 0, 'totalXp', COALESCE((SELECT amount FROM xp_transaction WHERE source_id = w.id AND reason = 'SESSION_COMPLETED'), 0), 'rulesetVersion', NULL)");
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        \assert(false !== $published);
        /** @var array{activity_types: list<array{source: string, provider_type: string, normalized_sport?: string}>, items: list<array<string, mixed>>} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        $snapshot['together'] = $settings;
        foreach ($snapshot['activity_types'] as &$row) {
            $row['normalized_sport'] = self::sport($row['provider_type']);
        }
        unset($row);
        // Le brouillon reste distinct du publié : aucune édition en attente n'est publiée ici.
        foreach ($this->connection->fetchAllAssociative('SELECT id, provider_type FROM game_activity_type') as $row) {
            $this->addSql('UPDATE game_activity_type SET normalized_sport = ? WHERE id = ?', [self::sport((string) $row['provider_type']), $row['id']]);
        }
        $gameplay = $snapshot;
        foreach ($gameplay['items'] as &$item) {
            unset($item['weapon_type']);
        }
        unset($item);
        $version = AlamRulesetVersion::of($gameplay);
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $revision = (int) $published['revision'] + 1;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [\Symfony\Component\Uid\Uuid::v7()->toRfc4122(), $revision, 'sport-ensemble-329', $version, $encoded]);
    }

    private static function sport(string $providerType): string
    {
        $key = str_starts_with($providerType, 'EXERCISE_TYPE_')
            ? strtolower(substr($providerType, 14))
            : strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $providerType));

        return match ($key) {
            'biking', 'biking_stationary' => 'cycling',
            'running_treadmill' => 'running',
            'swimming_pool', 'swimming_open_water' => 'swimming',
            'strength_training', 'traditional_strength_training', 'functional_strength_training', 'weightlifting' => 'strength_training',
            default => $key,
        };
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les compléments au ledger doivent conserver leur preuve.');
    }
}
