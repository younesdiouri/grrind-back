<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les mesures rapportées par la montre, et l'identifiant qui empêche le double crédit.
 *
 * Cinq colonnes, toutes nullables — aucun appareil ne fournit tout, et un `NOT NULL`
 * sur une table peuplée est de toute façon une migration qui échoue. L'absence signifie
 * « non mesuré » ; elle se distingue du `0`, qui signifie « mesuré, et nul ».
 *
 * `uniq_workout_external` est ce qui rend l'import idempotent. Pas un `SELECT` préalable :
 * entre le contrôle et l'écriture, deux synchronisations lancées par un client mobile qui
 * revient au premier plan passent toutes les deux. C'est le triplet
 * (joueur, source, identifiant fournisseur) qui autorise exactement ce qu'il faut — le
 * même workout vu par Apple Health et par Health Connect reste deux lignes, ce qui est
 * un chevauchement et se traite ailleurs (#91), pas un doublon.
 *
 * L'index est **partiel** : PostgreSQL considère deux NULL comme distincts, donc une
 * contrainte totale sur une colonne nullable n'interdirait rien, et le `WHERE` dit
 * explicitement ce qu'on protège plutôt que de le laisser deviner.
 *
 * Pas `CONCURRENTLY` : la table est vide, et un index concurrent ne peut pas s'exécuter
 * dans la transaction d'une migration.
 */
final class Version20260813075000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Training : métriques du workout et unicité sur l\'identifiant fournisseur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE workout ADD distance_meters INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD calories INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD elevation_gain_meters INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD average_heart_rate INT DEFAULT NULL');
        $this->addSql('ALTER TABLE workout ADD external_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_workout_external ON workout (user_id, source, external_id) WHERE (external_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_workout_external');
        $this->addSql('ALTER TABLE workout DROP external_id');
        $this->addSql('ALTER TABLE workout DROP average_heart_rate');
        $this->addSql('ALTER TABLE workout DROP elevation_gain_meters');
        $this->addSql('ALTER TABLE workout DROP calories');
        $this->addSql('ALTER TABLE workout DROP distance_meters');
    }
}
