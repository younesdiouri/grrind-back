<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Conserve les programmes sportifs fictifs indépendamment des séances des joueurs.
 */
final class Version20260913131342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Programmes hebdomadaires de game design';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_design_program (id UUID NOT NULL, name VARCHAR(80) NOT NULL, start_date VARCHAR(10) NOT NULL, timezone VARCHAR(80) NOT NULL, weeks INT NOT NULL, sessions JSON NOT NULL, energy JSON NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_design_program');
    }
}
