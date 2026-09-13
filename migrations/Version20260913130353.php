<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Isole les profils fictifs et les expériences reproductibles des données de jeu.
 */
final class Version20260913130353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Profils fictifs et expériences de game design';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_design_profile (id UUID NOT NULL, name VARCHAR(80) NOT NULL, strength INT NOT NULL, endurance INT NOT NULL, mobility INT NOT NULL, dexterity INT NOT NULL, vitality_override INT DEFAULT NULL, equipment JSON NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE game_design_run (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, engine_version VARCHAR(40) NOT NULL, kind VARCHAR(255) NOT NULL, input JSON NOT NULL, snapshots JSON NOT NULL, result JSON NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_design_profile');
        $this->addSql('DROP TABLE game_design_run');
    }
}
