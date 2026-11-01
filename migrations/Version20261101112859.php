<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/** #332 : retirer les bonus de caractéristiques en phase de développement, sans convertir leurs valeurs. */
final class Version20261101112859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réserver les caractéristiques aux efforts et les taux de combat aux équipements (#332).';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/WeeklyCharacteristicsUpgrade.php';
        $this->addSql("UPDATE game_item SET modifiers = COALESCE((SELECT jsonb_agg(modifier) FROM jsonb_array_elements(modifiers::jsonb) modifier WHERE modifier->>'type' NOT IN ('STRENGTH_BONUS', 'ENDURANCE_BONUS', 'MOBILITY_BONUS', 'DEXTERITY_BONUS')), '[]'::jsonb)::json");
        $this->addSql("UPDATE game_settings SET formulas = jsonb_build_object('hp', formulas::jsonb->'hp', 'damage', formulas::jsonb->'damage')::json WHERE id = 1");
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        \assert(false !== $published);
        /** @var array<string, mixed> $before */
        $before = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        $snapshot = WeeklyCharacteristicsUpgrade::snapshot($before);
        $version = WeeklyCharacteristicsUpgrade::version($snapshot);
        $revision = (int) $published['revision'] + 1;
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'weekly-characteristics-332', $version, $encoded]);
        foreach ($this->connection->fetchAllAssociative('SELECT id, rules FROM community_alam_run WHERE resolved_at IS NULL') as $run) {
            /** @var array<string, mixed> $before */
            $before = json_decode((string) $run['rules'], true, flags: \JSON_THROW_ON_ERROR);
            $rules = WeeklyCharacteristicsUpgrade::snapshot($before);
            $this->addSql('UPDATE community_alam_run SET rules = ?, ruleset_version = ? WHERE id = ? AND resolved_at IS NULL', [json_encode($rules, \JSON_THROW_ON_ERROR), WeeklyCharacteristicsUpgrade::version($rules), $run['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les publications historiques et ne pas réintroduire les bonus retirés.');
    }
}
