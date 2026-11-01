<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #334 : les arbres sortent de la V0 ; les compteurs sont supprimés sans reprise,
 * en phase de développement. Ledger, reçus et publications historiques restent intacts.
 *
 * Migration Doctrine : https://symfony.com/doc/current/doctrine.html#migrations-creating-the-database-tables-schema
 */
final class Version20261101123826 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retirer les points de compétences de la progression et des paliers administrables (#334).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_level DROP skill_points');
        $this->addSql('ALTER TABLE progression_snapshot DROP earned_skill_points');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Les points de compétences sont abandonnés ; leurs anciennes valeurs ne sont pas conservées.');
    }
}
