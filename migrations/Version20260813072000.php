<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `training_session` devient `workout`.
 *
 * **Écrite à la main, et c'est le sujet.** `migrations:diff` ne voit pas un renommage :
 * il voit une table qui disparaît et une autre qui apparaît, et propose `DROP` + `CREATE`.
 * Sur une base de développement peuplée ça perd les données ; sur n'importe quelle base
 * ça ment à qui relira la migration dans six mois, en racontant une suppression là où il
 * n'y a qu'un changement de vocabulaire.
 *
 * `ALTER TABLE … RENAME` ne touche pas les lignes : PostgreSQL réécrit une entrée de
 * catalogue, la table est renommée en temps constant même pleine.
 *
 * Les index et la clé primaire sont renommés avec elle. Ce n'est pas de la cosmétique :
 * `uniq_workout_active` est nommé dans le mapping de l'entité, et un nom qui diverge
 * ferait reproposer un `DROP` + `CREATE` de l'index à chaque `migrations:diff` suivant.
 * `workout_pkey` suit la convention que PostgreSQL aurait appliquée à la création.
 *
 * Rien d'autre ne bouge : aucune colonne ajoutée, retirée ou retypée. Les colonnes qui
 * porteront les métriques importées arrivent au ticket #84.
 */
final class Version20260813072000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training : training_session devient workout (renommage seul)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE training_session RENAME TO workout');
        $this->addSql('ALTER INDEX training_session_pkey RENAME TO workout_pkey');
        $this->addSql('ALTER INDEX idx_training_session_user_started RENAME TO idx_workout_user_started');
        $this->addSql('ALTER INDEX uniq_training_session_active RENAME TO uniq_workout_active');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX uniq_workout_active RENAME TO uniq_training_session_active');
        $this->addSql('ALTER INDEX idx_workout_user_started RENAME TO idx_training_session_user_started');
        $this->addSql('ALTER INDEX workout_pkey RENAME TO training_session_pkey');
        $this->addSql('ALTER TABLE workout RENAME TO training_session');
    }
}
