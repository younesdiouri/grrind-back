<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #165 : `daily_activity` — l'énergie active quotidienne d'un joueur, la moitié
 * « sédentarité » de Vitality. Une ligne par `(user, day)`, révisée par `UPSERT`, jamais
 * append-only : voir le docblock de `App\Training\Domain\DailyActivity`.
 *
 * **Trois lignes retirées du diff généré**, qui n'ont rien à voir avec ce ticket :
 * `doctrine:migrations:diff` propose systématiquement de supprimer puis recréer
 * `uniq_community_invite_code_live` et la contrainte composite
 * `fk_player_active_title_unlocked` (avec son index) — un artefact déjà documenté dans
 * plusieurs migrations précédentes (voir `Version20260819084411` et les suivantes) :
 * Doctrine ne sait pas relire un index partiel ni une clé composite écrits à la main
 * depuis le schéma introspecté, et les repropose à chaque `diff`. Aucune des trois n'est
 * touchée ici.
 */
final class Version20260827135439 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training : daily_activity porte l\'énergie active quotidienne d\'un joueur, une ligne par (user, day) (#165)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE daily_activity (id UUID NOT NULL, user_id UUID NOT NULL, day DATE NOT NULL, active_energy_kcal INT NOT NULL, source VARCHAR(32) NOT NULL, trust VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_daily_activity_user_day ON daily_activity (user_id, day)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE daily_activity');
    }
}
