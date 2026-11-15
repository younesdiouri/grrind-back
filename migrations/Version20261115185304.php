<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Ajout compatible : les guildes existantes reçoivent le même emblème que les nouvelles (#357). */
final class Version20261115185304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la composition bornée de l’emblème de guilde avec un défaut compatible.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild ADD emblem_symbol VARCHAR(16) DEFAULT \'DUMBBELL\' NOT NULL');
        $this->addSql('ALTER TABLE community_guild ADD emblem_background_color VARCHAR(16) DEFAULT \'NIGHT\' NOT NULL');
        $this->addSql('ALTER TABLE community_guild ADD emblem_symbol_color VARCHAR(16) DEFAULT \'CYAN\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild DROP emblem_symbol');
        $this->addSql('ALTER TABLE community_guild DROP emblem_background_color');
        $this->addSql('ALTER TABLE community_guild DROP emblem_symbol_color');
    }
}
