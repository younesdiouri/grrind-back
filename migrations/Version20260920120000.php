<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le défi PvP (#283) s'écrit sur `combat_battle` plutôt que dans une seconde table : même
 * timeline, même graine, même historique, même route de rejeu. Une colonne les distingue.
 *
 * **Nullable, et elle le restera** : `NULL` n'est pas ici une valeur manquante à combler un
 * jour, c'est « ce combat opposait un joueur à une entrée du catalogue ». Les lignes déjà
 * écrites sont donc justes telles quelles, sans reprise de données — et une éventuelle
 * contrainte `NOT NULL` posée plus tard signifierait exactement le contraire de ce que la
 * colonne dit.
 *
 * Le pseudo du défié voyage dans `enemy_snapshot` (`key: null`, `name: <pseudo>`), colonne
 * JSONB existante : la forme y était déjà libre, et un combat relu ne doit dépendre ni d'un
 * renommage postérieur ni de la présence du compte adverse.
 *
 * Pas d'index : `opponent_id` ne filtre aucune lecture en v1 — l'historique reste celui du
 * **défieur** (`player_id`), et le défié ne voit pas le combat. L'index s'ajoutera avec la
 * requête qui en aura besoin, pas avant.
 */
final class Version20260920120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Un combat peut opposer deux joueurs (#283).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE combat_battle ADD opponent_id UUID DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN combat_battle.opponent_id IS \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE combat_battle DROP opponent_id');
    }
}
