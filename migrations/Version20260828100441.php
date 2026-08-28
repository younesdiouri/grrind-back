<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les Risālāt (#191) : le défi sportif qu'un membre envoie à sa guilde, et le tour de
 * rotation qui décide qui l'envoie.
 *
 * Une seule table, parce qu'un tour et une Risāla sont la même chose à deux moments de leur
 * vie — voir le docblock de `App\Community\Domain\Risala`. Un tour manqué reste en base avec
 * son cycle et son expéditeur : c'est ce qui fait qu'il compte dans la rotation.
 *
 * **Amputée à la main**, comme toutes les migrations de ce module. Le diff proposait aussi
 * de supprimer l'index partiel `uniq_community_invite_code_live` et la contrainte composite
 * `fk_player_active_title_unlocked` avec son index — deux artefacts qu'il ne sait pas relire
 * depuis le schéma, et qu'il redemande à chaque génération. Les appliquer casserait deux
 * garde-fous en production sans qu'aucun test le voie.
 *
 * L'index partiel de ce ticket, lui, **est** déclaré dans le mapping
 * (`#[ORM\UniqueConstraint(… options: ['where' => …])]`) : DBAL sait le relire, donc il ne
 * reviendra pas hanter les diffs suivants. C'est le chemin à préférer désormais pour les
 * nouveaux index partiels, plutôt que le SQL nu de `uniq_community_invite_code_live`.
 */
final class Version20260828100441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Risālāt : défis de guilde, rotation hebdomadaire (#191)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_risala (id UUID NOT NULL, guild_id UUID NOT NULL, sender_id UUID NOT NULL, cycle INT NOT NULL, discipline VARCHAR(16) DEFAULT NULL, drawn_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, deadline TIMESTAMP(0) WITH TIME ZONE NOT NULL, chosen_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, revealed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, status VARCHAR(16) NOT NULL, draw_roll INT NOT NULL, draw_pool_size INT NOT NULL, PRIMARY KEY (id))');

        // Toutes les lectures partent de la guilde : ses Risālāt vivantes, son tour ouvert,
        // les expéditeurs de son cycle.
        $this->addSql('CREATE INDEX idx_community_risala_guild ON community_risala (guild_id)');

        // Au plus un tour ouvert par guilde, tenu par la base et non par un `if` dans la
        // bascule : entre le SELECT qui vérifie et l'INSERT qui écrit, deux exécutions
        // concurrentes passent toutes les deux. Partiel, parce qu'une guilde accumule autant
        // de tours scellés qu'elle a de semaines derrière elle.
        $this->addSql('CREATE UNIQUE INDEX uniq_community_risala_open_turn ON community_risala (guild_id) WHERE (status = \'DRAWN\')');

        // Dissoudre une guilde emporte ses Risālāt : elles ne désignent plus personne.
        $this->addSql('ALTER TABLE community_risala ADD CONSTRAINT FK_D1A236A65F2131EF FOREIGN KEY (guild_id) REFERENCES community_guild (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_risala DROP CONSTRAINT FK_D1A236A65F2131EF');
        $this->addSql('DROP TABLE community_risala');
    }
}
