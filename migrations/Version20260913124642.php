<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** La révision du brouillon est indépendante du pointeur publié déjà présent. */
final class Version20260913124642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la révision commune du brouillon de game design.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_ruleset ADD draft_revision INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_ruleset DROP draft_revision');
    }
}
