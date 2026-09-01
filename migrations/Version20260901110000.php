<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase B du #256, séparée de la contraction de contrat : les contrôles après le déploiement
 * de la Phase A ont établi que la table est vide et qu'aucune image déployée ne la lit encore.
 * Le `down()` recrée exactement sa forme initiale, vide, pour garder cette migration réversible
 * sans ressusciter de données ni mélanger un autre changement de schéma.
 */
final class Version20260901110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : suppression de la table de fenêtres de notifications retirée (#256)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE shared_pending_session_credit');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE shared_pending_session_credit (player_id UUID NOT NULL, window_id UUID NOT NULL, opened_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, sessions_count INT NOT NULL, total_xp_granted INT NOT NULL, last_discipline VARCHAR(16) NOT NULL, last_duration_seconds INT NOT NULL, initial_level INT NOT NULL, current_level INT NOT NULL, PRIMARY KEY (player_id))');
    }
}
