<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Les rendements décroissants (#344) quittent le brouillon et le snapshot publié : plus aucun
 * code ne les lit, et un réglage administrable qui ne fait rien serait un contrat qui ment.
 *
 * Décision destructive assumée en phase de dev : l'XP déjà accordée sous l'ancienne règle
 * n'est pas recalculée. Ses lignes `DIMINISHING` restent au ledger, sous l'ancienne empreinte.
 */
final class Version20261107120000 extends AbstractMigration
{
    private const array KEYS = ['diminishing_returns', 'diminishing_returns_beyond_percent'];

    public function getDescription(): string
    {
        return 'Supprime les rendements décroissants du réglage XP (#344).';
    }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/AlamRulesetVersion.php';
        $this->addSql("UPDATE game_settings SET xp = (xp::jsonb - 'diminishing_returns' - 'diminishing_returns_beyond_percent')::json WHERE id = 1");
        $published = $this->connection->fetchAssociative('SELECT revision, snapshot FROM game_ruleset WHERE id = 1 FOR UPDATE');
        if (false === $published) {
            return;
        }
        /** @var array{xp: array<string, mixed>, items?: list<array<string, mixed>>} $snapshot */
        $snapshot = json_decode((string) $published['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        if ([] === array_intersect(self::KEYS, array_keys($snapshot['xp']))) {
            return;
        }
        foreach (self::KEYS as $key) {
            unset($snapshot['xp'][$key]);
        }
        // Même empreinte que #319 : weapon_type, présentation seule, en est exclu.
        $gameplay = $snapshot;
        foreach ($gameplay['items'] ?? [] as $index => $item) {
            unset($gameplay['items'][$index]['weapon_type']);
        }
        $version = AlamRulesetVersion::of($gameplay);
        $encoded = json_encode($snapshot, \JSON_THROW_ON_ERROR);
        $revision = (int) $published['revision'] + 1;
        $this->addSql('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ?, draft_revision = draft_revision + 1, published_at = CURRENT_TIMESTAMP WHERE id = 1', [$encoded, $version, $revision]);
        $this->addSql('INSERT INTO game_publication (id, published_at, revision, author, version, snapshot) VALUES (?, CURRENT_TIMESTAMP, ?, ?, ?, ?)', [Uuid::v7()->toRfc4122(), $revision, 'sans-rendements-344', $version, $encoded]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les rendements décroissants ne reviennent pas par migration.');
    }
}
