<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Première table du module Training : la séance de sport.
 *
 * Pas de clé étrangère vers identity_user, et ce n'est pas un oubli : les modules ne
 * s'importent pas, l'agrégat porte un user_id nu. La contrainte d'intégrité qu'on perd
 * ici est celle qu'on gagne en frontière — l'UUID vient d'un jeton vérifié.
 *
 * L'index (user_id, started_at) sert les deux seules lectures prévues : l'historique
 * d'un joueur, du plus récent au plus ancien, et la recherche de sa séance en cours.
 * L'unicité de cette dernière viendra avec les garde-fous, par un index partiel.
 *
 * TIMESTAMPTZ partout, stockage UTC. duration_seconds est un entier : jamais de
 * flottant sur une valeur de jeu persistée.
 */
final class Version20260810091244 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training : table training_session';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE training_session (id UUID NOT NULL, user_id UUID NOT NULL, discipline VARCHAR(32) NOT NULL, source VARCHAR(32) NOT NULL, trust VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, started_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, duration_seconds INT DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_training_session_user_started ON training_session (user_id, started_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_session');
    }
}
