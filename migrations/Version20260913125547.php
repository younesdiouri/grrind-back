<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Les sources reproduisent les calculs antérieurs ; aucun brouillon n'est publié durant la conversion. */
final class Version20260913125547 extends AbstractMigration
{
    public function getDescription(): string { return 'Rend configurables les attributs sources des statistiques de combat (#277).'; }

    public function up(Schema $schema): void
    {
        require_once __DIR__.'/CombatFormulaSeed.php';
        require_once __DIR__.'/CombatV2Version.php';
        $formulas = CombatFormulaSeed::data();
        $this->addSql('ALTER TABLE game_settings ADD formulas JSON NOT NULL DEFAULT \'{}\'');
        $this->addSql('UPDATE game_settings SET formulas = ? WHERE id = 1', [json_encode($formulas, JSON_THROW_ON_ERROR)]);
        $stored = $this->connection->fetchOne('SELECT snapshot FROM game_ruleset WHERE id = 1');
        \assert(\is_string($stored));
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        \assert(\is_array($snapshot['combat']));
        $snapshot['combat']['formulas'] = $formulas;
        $this->addSql('UPDATE game_ruleset SET revision = revision + 1, version = ?, snapshot = ? WHERE id = 1', [CombatV2Version::of($snapshot), json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les formules modifiées ne correspondent plus nécessairement aux anciens calculs codés.');
    }
}
