<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Garde durablement les clés qui ont déjà figuré dans une révision active. */
final class Version20260901183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mémorise les configurations déjà publiées actives pour supprimer sans course (#260)';
    }

    public function up(Schema $schema): void
    {
        foreach (['game_item', 'game_title', 'game_enemy', 'game_loot_table'] as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN IF NOT EXISTS ever_published_active BOOLEAN NOT NULL DEFAULT FALSE', $table));
            // Les lignes livrées par le seed existaient déjà dans une révision active avant
            // l'ajout de ce registre ; les rendre supprimables rouvrirait la course historique.
            $this->addSql(sprintf('UPDATE %s SET ever_published_active = TRUE WHERE active = TRUE', $table));
        }
    }

    public function down(Schema $schema): void
    {
        // Le registre protège les faits déjà écrits : il n'est pas supprimé au rollback.
    }
}
