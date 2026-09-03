<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use Symfony\Component\Uid\Uuid;

/**
 * Déplace le reste de l'équilibrage dans les tables éditables. Les colonnes JSON portent un
 * défaut transitoire seulement pour pouvoir mettre à niveau une base existante avant le seed.
 */
final class Version20260903060248 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin : prépare les catalogues et réglages d’équilibrage restants (#264)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_activity_type (id UUID NOT NULL, source VARCHAR(30) NOT NULL, provider_type VARCHAR(120) NOT NULL, discipline VARCHAR(30) NOT NULL, active BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_activity_source_provider ON game_activity_type (source, provider_type)');
        $this->addSql('CREATE TABLE game_discipline (id UUID NOT NULL, discipline VARCHAR(30) NOT NULL, active BOOLEAN NOT NULL, sort_order INT NOT NULL, credits_xp BOOLEAN NOT NULL, daily_cap_xp INT DEFAULT NULL, xp_per_km INT DEFAULT NULL, xp_per_100m_elevation INT DEFAULT NULL, split JSON DEFAULT NULL, translations JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_discipline ON game_discipline (discipline)');
        $this->addSql('CREATE TABLE game_level (id UUID NOT NULL, level INT NOT NULL, total_xp INT NOT NULL, skill_points INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_game_level ON game_level (level)');
        $this->addSql("ALTER TABLE game_settings ADD training JSON NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE game_settings ADD xp JSON NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE game_settings ADD attributes JSON NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE game_settings ADD community JSON NOT NULL DEFAULT '{}'");
        $this->addSql("ALTER TABLE game_settings ADD notifications JSON NOT NULL DEFAULT '{}'");

        require_once __DIR__.'/GameBalanceSeed.php';
        $seed = GameBalanceSeed::data();
        /** @var list<array<string, mixed>> $disciplines */
        $disciplines = $seed['disciplines'];
        /** @var list<array<string, mixed>> $levels */
        $levels = $seed['levels'];
        /** @var list<array<string, mixed>> $activityTypes */
        $activityTypes = $seed['activity_types'];
        foreach ($disciplines as $discipline) {
            $this->insert('game_discipline', [
                'id' => Uuid::v7()->toRfc4122(), 'discipline' => $discipline['discipline'], 'active' => $discipline['active'], 'sort_order' => $discipline['sort_order'],
                'credits_xp' => $discipline['credits_xp'], 'daily_cap_xp' => $discipline['daily_cap_xp'], 'xp_per_km' => $discipline['xp_per_km'],
                'xp_per_100m_elevation' => $discipline['xp_per_100m_elevation'], 'split' => null === $discipline['split'] ? null : json_encode($discipline['split'], JSON_THROW_ON_ERROR),
                'translations' => json_encode($discipline['translations'], JSON_THROW_ON_ERROR),
            ]);
        }
        foreach ($levels as $level) {
            $this->insert('game_level', ['id' => Uuid::v7()->toRfc4122(), 'level' => $level['level'], 'total_xp' => $level['total_xp'], 'skill_points' => $level['skill_points']]);
        }
        foreach ($activityTypes as $activityType) {
            $this->insert('game_activity_type', ['id' => Uuid::v7()->toRfc4122(), 'source' => $activityType['source'], 'provider_type' => $activityType['provider_type'], 'discipline' => $activityType['discipline'], 'active' => $activityType['active']]);
        }
        foreach (['training', 'xp', 'attributes', 'community', 'notifications'] as $key) {
            $this->addSql(sprintf('UPDATE game_settings SET %s = ?', $key), [json_encode($seed[$key], JSON_THROW_ON_ERROR)]);
        }

        $stored = $this->connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
        \assert(\is_string($stored));
        $snapshot = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot));
        $snapshot = [...$snapshot, ...array_intersect_key($seed, array_flip(['training', 'xp', 'attributes', 'disciplines', 'levels', 'activity_types', 'community', 'notifications']))];
        $this->addSql('UPDATE game_ruleset SET revision = revision + 1, version = ?, snapshot = ?, published_at = ? WHERE id = 1', [GameRulesetVersion::of($snapshot), json_encode($snapshot, JSON_THROW_ON_ERROR), (new \DateTimeImmutable())->format('c')]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_activity_type');
        $this->addSql('DROP TABLE game_discipline');
        $this->addSql('DROP TABLE game_level');
        $this->addSql('ALTER TABLE game_settings DROP training');
        $this->addSql('ALTER TABLE game_settings DROP xp');
        $this->addSql('ALTER TABLE game_settings DROP attributes');
        $this->addSql('ALTER TABLE game_settings DROP community');
        $this->addSql('ALTER TABLE game_settings DROP notifications');
    }

    /** @param array<string, scalar|null> $values */
    private function insert(string $table, array $values): void
    {
        $columns = array_keys($values);
        $values = array_map(static fn (mixed $value): mixed => is_bool($value) ? ($value ? 'true' : 'false') : $value, $values);
        $this->addSql(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))), array_values($values));
    }

    /** @param array<string, mixed> $snapshot */
    private static function version(array $snapshot): string
    {
        foreach (['items', 'titles', 'disciplines'] as $section) {
            foreach ($snapshot[$section] ?? [] as &$row) {
                \assert(\is_array($row));
                unset($row['image_path'], $row['translations']);
            }
            unset($row);
        }
        foreach (['enemies', 'bosses'] as $kind) {
            foreach ($snapshot['combat'][$kind] ?? [] as &$enemy) {
                \assert(\is_array($enemy));
                unset($enemy['translations']);
            }
            unset($enemy);
        }

        return 'v1-'.substr(hash('sha256', json_encode(self::canonicalize($snapshot), JSON_THROW_ON_ERROR)), 0, 12);
    }

    /** @param array<int|string, mixed> $values @return array<int|string, mixed> */
    private static function canonicalize(array $values): array
    {
        if (!array_is_list($values)) {
            ksort($values);
        }
        foreach ($values as $key => $value) {
            if (\is_array($value)) {
                $values[$key] = self::canonicalize($value);
            }
        }

        return $values;
    }
}
