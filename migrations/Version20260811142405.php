<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les titres d'un joueur : ce qu'il a débloqué, et ce qu'il affiche.
 *
 * **Deux tables et non une colonne de plus.** `player_title` enregistre un fait acquis —
 * une ligne y est définitive, aucun code ne sait la retirer, c'est ce qui porte l'exigence
 * « un titre débloqué ne se reprend jamais ». `player_active_title` enregistre une
 * préférence, qui change autant que le joueur veut. Les mêler imposerait un index partiel
 * « un seul actif par compte » et deux `UPDATE` ordonnés pour en changer, là où une ligne
 * remplacée d'un `INSERT … ON CONFLICT` suffit.
 *
 * Le couple (compte, titre) **est** la clé primaire de `player_title` : pas d'identifiant
 * propre, et l'idempotence du déblocage vient de la structure plutôt que d'un `SELECT`
 * préalable — deux évaluations concurrentes ne peuvent pas écrire deux fois le même titre.
 *
 * La **clé étrangère composée** de `player_active_title` vers `player_title` est le seul
 * lien entre les deux : afficher un titre non débloqué devient impossible au niveau de la
 * base, pas seulement au niveau du code. `ON DELETE CASCADE` parce que la seule suppression
 * prévue est celle d'un compte entier (#43) : le titre affiché n'a pas à survivre aux
 * déblocages qui le justifiaient.
 *
 * `title_id` n'est en revanche référence de rien : le catalogue est du config-as-code, il
 * n'a pas de table. Un titre retiré du YAML laisse donc des lignes orphelines,
 * délibérément — elles ne s'affichent plus, et elles reviennent intactes le jour où on
 * remet le titre.
 *
 * Pas de clé étrangère vers `identity_user`, comme sur le ledger et le snapshot : `Identity`
 * est un autre module, et la frontière vaut pour les tables autant que pour les classes.
 */
final class Version20260811142405 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : player_title et player_active_title';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE player_title (user_id UUID NOT NULL, title_id VARCHAR(64) NOT NULL, unlocked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id, title_id))');
        $this->addSql('CREATE TABLE player_active_title (user_id UUID NOT NULL, title_id VARCHAR(64) NOT NULL, selected_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id))');

        // Ajoutée à la main : Doctrine ne sait pas exprimer une clé étrangère composée sans
        // en faire une association entre entités, et une association ferait entrer le
        // chargement d'une collection là où on ne veut qu'une contrainte.
        $this->addSql(<<<'SQL'
            ALTER TABLE player_active_title
                ADD CONSTRAINT fk_player_active_title_unlocked
                FOREIGN KEY (user_id, title_id) REFERENCES player_title (user_id, title_id)
                ON DELETE CASCADE
            SQL);
    }

    public function down(Schema $schema): void
    {
        // La contrainte part avec la table qui la porte ; l'ordre suffit à ce que le second
        // `DROP` ne bute pas sur elle.
        $this->addSql('DROP TABLE player_active_title');
        $this->addSql('DROP TABLE player_title');
    }
}
