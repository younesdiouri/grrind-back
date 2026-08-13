<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Un workout n'a plus d'état : il naît terminé.
 *
 * `status` et l'index qui garantissait « au plus une séance ACTIVE par joueur »
 * disparaissent avec le chronomètre (#85). Il n'y a plus rien à sérialiser : Apple
 * produit trois workouts d'affilée sans demander la permission à personne, et le modèle
 * ne peut pas refuser un fait qui a eu lieu.
 *
 * **Cette migration détruit des lignes, et c'est délibéré.** Les séances en cours n'ont
 * pas de fin ; dans le nouveau modèle, un workout sans `ended_at` n'existe pas, et il
 * n'y a aucune valeur honnête à inventer — ni `NOW()`, qui daterait la séance de la
 * migration, ni `started_at`, qui créditerait zéro. Elles sont supprimées avant le
 * `SET NOT NULL`, qui échouerait sinon.
 *
 * Le `DELETE` est sans risque ici et ne le sera plus jamais : aucun compte en production,
 * et c'est précisément la fenêtre où ce virage coûte une migration plutôt qu'une reprise
 * de données. Les séances **closes**, elles, sont conservées intégralement.
 */
final class Version20260813090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training : le workout perd son statut et son état actif';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM workout WHERE ended_at IS NULL OR duration_seconds IS NULL');
        $this->addSql('DROP INDEX uniq_workout_active');
        $this->addSql('ALTER TABLE workout DROP status');
        $this->addSql('ALTER TABLE workout ALTER COLUMN ended_at SET NOT NULL');
        $this->addSql('ALTER TABLE workout ALTER COLUMN duration_seconds SET NOT NULL');
    }

    /**
     * Le retour rend la forme, pas les lignes supprimées : une migration descendante
     * restaure un schéma, elle n'a jamais su ressusciter des données. `COMPLETED` est le
     * seul statut que puissent porter les lignes qui restent — elles ont toutes une fin.
     */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout ALTER COLUMN duration_seconds DROP NOT NULL');
        $this->addSql('ALTER TABLE workout ALTER COLUMN ended_at DROP NOT NULL');
        $this->addSql("ALTER TABLE workout ADD status VARCHAR(16) NOT NULL DEFAULT 'COMPLETED'");
        $this->addSql('ALTER TABLE workout ALTER COLUMN status DROP DEFAULT');
        $this->addSql("CREATE UNIQUE INDEX uniq_workout_active ON workout (user_id) WHERE (status = 'ACTIVE')");
    }
}
