<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Le streak devient l'assiduité (#346) : les jours pointés, et l'audit des coffres recréé
 * par `(joueur, lundi)`. Le réglage `streak` perd `minimum_daily_seconds` et le palier EPIC.
 *
 * Décision destructive assumée en phase de dev : les coffres du #286 déjà attribués restent
 * à l'inventaire, mais leur audit part avec l'ancienne table.
 */
final class Version20261107180000 extends AbstractMigration
{
    private const array STREAK = ['chests' => ['COMMON' => 'WOODEN_CHEST', 'RARE' => 'IRON_BOUND_CHEST', 'LEGENDARY' => 'ANCESTRAL_CHEST']];

    public function getDescription(): string
    {
        return 'Streak : pointage quotidien et coffre hebdo par palier de sport (#346).';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $this->addSql('CREATE TABLE engagement_check_in (user_id UUID NOT NULL, day DATE NOT NULL, PRIMARY KEY (user_id, day))');
        $this->addSql('DROP TABLE engagement_streak_chest');
        $this->addSql('CREATE TABLE engagement_streak_chest (id UUID NOT NULL, user_id UUID NOT NULL, week_start DATE NOT NULL, sport_days INT NOT NULL, rarity VARCHAR(20) NOT NULL, item_key VARCHAR(64) NOT NULL, ruleset_version VARCHAR(64) NOT NULL, granted_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_streak_chest_week ON engagement_streak_chest (user_id, week_start)');

        $streak = json_encode(self::STREAK, \JSON_THROW_ON_ERROR);
        $this->addSql('UPDATE game_settings SET streak = ? WHERE id = 1', [$streak]);
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        if (false === $published) {
            return;
        }
        /** @var array{streak?: array<string, mixed>, items?: list<array<string, mixed>>} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        $snapshot['streak'] = self::STREAK;
        // Même empreinte que #319 : weapon_type, présentation seule, en est exclu.
        $gameplay = $snapshot;
        foreach ($gameplay['items'] ?? [] as $index => $item) {
            unset($gameplay['items'][$index]['weapon_type']);
        }
        $version = AlamRulesetVersion::of($gameplay);
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $revision = (int) $published['revision'] + 1;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'streak-assiduite-346', $version, $encoded]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les jours pointés ne se reconvertissent pas en jours sportifs.');
    }
}
