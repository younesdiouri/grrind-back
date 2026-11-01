<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/** #332 : inscriptions opt-in ; les éditions déjà résolues conservent leurs trois rencontres. */
final class Version20261101112442 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Inscription persistante, difficulté hebdomadaire et loot distinct de Ālam (#332).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild_membership ADD alam_registered BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE community_alam_run ADD difficulty VARCHAR(16) DEFAULT NULL');
        $this->addSql("UPDATE community_alam_run SET difficulty = 'EASY' WHERE resolved_at IS NULL");
        // Valeurs provisoires distinctes, administrables ; aucun équilibrage final ici.
        $loot = [
            'EASY' => ['resource_base' => 1, 'resource_progression' => 4, 'equipment_chance_permille' => 50, 'legendary_chance_permille' => 0],
            'INTERMEDIATE' => ['resource_base' => 2, 'resource_progression' => 6, 'equipment_chance_permille' => 100, 'legendary_chance_permille' => 5],
            'HARD' => ['resource_base' => 3, 'resource_progression' => 8, 'equipment_chance_permille' => 150, 'legendary_chance_permille' => 10],
        ];
        $this->addSql("UPDATE game_settings SET alam = jsonb_set(alam::jsonb, '{difficulty_loot}', ?::jsonb)::json WHERE id = 1", [json_encode($loot, \JSON_THROW_ON_ERROR)]);
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        \assert(false !== $published);
        /** @var array{items: list<array<string, mixed>>, alam: array<string, mixed>} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        $snapshot['alam']['difficulty_loot'] = $loot;
        $version = self::versionOf($snapshot);
        $revision = (int) $published['revision'] + 1;
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'weekly-alam-332', $version, $encoded]);
        // Une édition non résolue adopte le loot sans mélanger deux versions de règles.
        foreach ($this->connection->fetchAllAssociative('SELECT id, rules FROM community_alam_run WHERE resolved_at IS NULL') as $run) {
            /** @var array{items: list<array<string, mixed>>, alam: array<string, mixed>} $rules */
            $rules = json_decode((string) $run['rules'], true, flags: \JSON_THROW_ON_ERROR);
            $rules['alam']['difficulty_loot'] = $loot;
            $this->addSql('UPDATE community_alam_run SET rules = ?, ruleset_version = ? WHERE id = ? AND resolved_at IS NULL', [json_encode($rules, \JSON_THROW_ON_ERROR), self::versionOf($rules), $run['id']]);
        }
    }

    /** @param array{items: list<array<string, mixed>>} $snapshot */
    private static function versionOf(array $snapshot): string
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        foreach ($snapshot['items'] as &$item) {
            unset($item['weapon_type']);
        }
        unset($item);

        return AlamRulesetVersion::of($snapshot);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Conserver les inscriptions, les résultats et leurs publications auditées.');
    }
}
