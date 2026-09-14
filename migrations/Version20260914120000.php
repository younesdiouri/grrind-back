<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ajout de contenu initial isolé : ne publie aucun brouillon et ne touche aucun ledger historique. */
final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Éditions Alam et réglages publiés initiaux (#279)';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $rules = ['strength_target' => 100, 'endurance_target' => 100, 'mobility_target' => 100, 'dexterity_target' => 100, 'vitality_target' => 400, 'presentation_seconds' => 60, 'memory_limit' => 20, 'resource_base' => 1, 'resource_progression' => 4, 'activity_cap_permille' => 3000, 'deficit_exponent' => 8, 'rng_cap_permille' => 250, 'rng_base_permille' => 200, 'surplus_weight_permille' => 100, 'equipment_chance_permille' => 100, 'legendary_chance_permille' => 10, 'start_hour' => 19, 'reset_hour' => 20, 'timezone' => 'Europe/Paris', 'resource_key' => 'NAFS_ESSENCE', 'enemy_key' => 'AL_KASAL', 'encounter_thresholds' => [250, 550, 1000]];
        $this->addSql("ALTER TABLE game_settings ADD alam JSON NOT NULL DEFAULT '{}'::json");
        $this->addSql('ALTER TABLE game_settings ALTER alam DROP DEFAULT');
        $this->addSql('UPDATE game_settings SET alam = ? WHERE id = 1', [json_encode($rules, JSON_THROW_ON_ERROR)]);
        $stored = $this->connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
        \assert(\is_string($stored));
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        $snapshot['alam'] = $rules;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = revision + 1 WHERE id = 1', [json_encode($snapshot, JSON_THROW_ON_ERROR), AlamRulesetVersion::of($snapshot)]);
        $this->addSql('CREATE TABLE community_alam_run (id UUID NOT NULL, guild_id UUID NOT NULL, mode VARCHAR(16) NOT NULL, week_starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, collection_ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revealed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, frozen_target_count INT NOT NULL, ruleset_version VARCHAR(32) NOT NULL, rules JSON NOT NULL, result JSON NOT NULL, seed VARCHAR(64) NOT NULL, PRIMARY KEY(id))');
        $this->addSql("CREATE UNIQUE INDEX uniq_alam_week ON community_alam_run (guild_id, week_starts_at) WHERE mode = 'WEEKLY'");
        $this->addSql('CREATE INDEX idx_alam_guild_id ON community_alam_run (guild_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les résultats et récompenses Alam sont des faits auditables.');
    }
}
