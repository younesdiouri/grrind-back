<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Présentation optionnelle des ennemis ; les snapshots historiques restent inchangés.
 */
final class Version20260907152810 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add three nullable enemy pose image paths';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_enemy ADD idle_image_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE game_enemy ADD attack_image_path VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE game_enemy ADD hit_image_path VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_enemy DROP idle_image_path');
        $this->addSql('ALTER TABLE game_enemy DROP attack_image_path');
        $this->addSql('ALTER TABLE game_enemy DROP hit_image_path');
    }
}
