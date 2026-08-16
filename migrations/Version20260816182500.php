<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le module Community : les guildes et leurs adhésions (#114).
 *
 * **Relue et amputée à la main.** Le diff proposait au passage de supprimer la contrainte
 * `fk_player_active_title_unlocked` et son index — une contrainte composite écrite à la
 * main que Doctrine ne sait pas relire dans le mapping, donc qu'il croit en trop à chaque
 * génération. L'appliquer aurait laissé un titre actif survivre à la perte de son
 * déblocage. C'est précisément pourquoi les migrations ne se génèrent pas à l'aveugle.
 *
 * L'index unique de `community_guild_membership` porte sur **`player_id` seul**. Le couple
 * `(guild_id, player_id)` n'aurait interdit que d'entrer deux fois dans la même guilde ;
 * la règle est qu'un joueur n'appartient qu'à une guilde, et c'est cet index qui la tient
 * face à deux requêtes concurrentes qu'aucun `SELECT` préalable ne départagerait.
 *
 * `ON DELETE CASCADE` double le `cascade: remove` de l'ORM : dissoudre une guilde emporte
 * ses adhésions même si la ligne part sans passer par l'entité. Sans ça, un membre resterait
 * attaché à une guilde disparue — et l'index unique l'empêcherait d'en rejoindre une autre.
 */
final class Version20260816182500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community : guildes et adhésions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_guild (id UUID NOT NULL, name VARCHAR(40) NOT NULL, created_by UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');

        $this->addSql('CREATE TABLE community_guild_membership (id UUID NOT NULL, guild_id UUID NOT NULL, player_id UUID NOT NULL, role VARCHAR(16) NOT NULL, joined_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');

        // La liste des membres part toujours de la guilde : sans cet index, l'écran
        // principal du module ferait un parcours séquentiel de toutes les adhésions.
        $this->addSql('CREATE INDEX idx_community_membership_guild ON community_guild_membership (guild_id)');

        $this->addSql('CREATE UNIQUE INDEX uniq_community_membership_player ON community_guild_membership (player_id)');

        $this->addSql('ALTER TABLE community_guild_membership ADD CONSTRAINT fk_community_membership_guild FOREIGN KEY (guild_id) REFERENCES community_guild (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild_membership DROP CONSTRAINT fk_community_membership_guild');
        $this->addSql('DROP TABLE community_guild_membership');
        $this->addSql('DROP TABLE community_guild');
    }
}
