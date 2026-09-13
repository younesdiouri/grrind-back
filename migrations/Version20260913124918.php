<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Conserve les publications futures sans réécrire les snapshots historiques des joueurs. */
final class Version20260913124918 extends AbstractMigration
{
    public function getDescription(): string { return 'Archive les règles publiées avec leur auteur.'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE game_publication (id UUID NOT NULL, published_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revision INT NOT NULL, author VARCHAR(180) NOT NULL, version VARCHAR(32) NOT NULL, snapshot JSON NOT NULL, PRIMARY KEY (id))');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_publication');
    }
}
