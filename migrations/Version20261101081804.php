<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/** #35 : départ en Bronze, sans reconstruction de ligues pour les semaines antérieures au lancement. */
final class Version20261101081804 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ligues hebdomadaires, résultats figés et réglages publiés (#35).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE engagement_league_period (starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, settled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, ruleset_version VARCHAR(32) DEFAULT NULL, PRIMARY KEY (starts_at))');
        $this->addSql('CREATE TABLE engagement_league_result (player_id UUID NOT NULL, starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, tier VARCHAR(16) NOT NULL, next_tier VARCHAR(16) NOT NULL, score INT NOT NULL, rank INT DEFAULT NULL, participant_count INT NOT NULL, PRIMARY KEY (player_id, starts_at))');
        $this->addSql('ALTER TABLE game_settings ADD league JSON NOT NULL DEFAULT \'{}\'');
        $this->addSql('CREATE INDEX idx_xp_transaction_occurred ON xp_transaction (occurred_at)');
        $start = new DateTimeImmutable('monday this week', new DateTimeZone('Europe/Paris'))->setTime(0, 0);
        $this->addSql('INSERT INTO engagement_league_period (starts_at, ends_at) VALUES (?, ?)', [$start->format('c'), $start->modify('+1 week')->format('c')]);

        $settings = ['movement_percent' => 20, 'minimum_players_for_relegation' => 5, 'loot_luck_per_tier_percent' => 5];
        $this->addSql('UPDATE game_settings SET league = ?', [json_encode($settings, \JSON_THROW_ON_ERROR)]);
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        \assert(false !== $published);
        /** @var array{items: list<array<string, mixed>>, league?: array<string, int>} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        // Ne publier que cette section, sans embarquer les autres brouillons en attente.
        $snapshot['league'] = $settings;
        require_once __DIR__.'/AlamRulesetVersion.php';
        $gameplay = $snapshot;
        foreach ($gameplay['items'] as &$item) {
            unset($item['weapon_type']);
        }
        unset($item);
        $version = AlamRulesetVersion::of($gameplay);
        $revision = (int) $published['revision'] + 1;
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'leagues-35', $version, $encoded]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les résultats et publications audités des ligues sont conservés.');
    }
}
