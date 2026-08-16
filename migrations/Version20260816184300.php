<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le code d'invitation d'une guilde (#116).
 *
 * **Relue et amputée à la main**, comme au #114 : le diff proposait à nouveau de supprimer
 * `fk_player_active_title_unlocked`, une contrainte composite qu'il ne sait pas relire dans
 * le mapping. Il voulait aussi renommer `idx_community_membership_guild` en son hash — le
 * mapping déclare désormais le nom, ce qui coupe ce faux positif à la source.
 *
 * ————— L'index unique **partiel** —————————————————————————————————————————————————————
 *
 * `WHERE revoked_at IS NULL` est ce qui fait tenir « un seul code vivant par guilde »
 * *dans la base*, et pas dans un handler. Sans le prédicat, l'unicité porterait sur tout
 * l'historique et interdirait de régénérer un code après en avoir révoqué un — alors que
 * régénérer est précisément le geste qu'on veut rendre possible.
 *
 * C'est aussi ce qui rattrape deux générations simultanées : elles révoquent chacune
 * l'ancien code et insèrent chacune le leur, et l'index refuse la seconde. Le verrou de
 * ligne pris par `IssueInviteCodeHandler` fait qu'on n'y arrive normalement jamais ; cet
 * index est le filet, pas le mécanisme.
 *
 * L'unicité sur `code` seul, elle, est totale : un code révoqué ne doit jamais pouvoir
 * être retiré au hasard et désigner deux guildes selon la date.
 */
final class Version20260816184300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Community : codes d\'invitation, un seul vivant par guilde';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_guild_invite_code (id UUID NOT NULL, guild_id UUID NOT NULL, code VARCHAR(8) NOT NULL, issued_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');

        $this->addSql('CREATE INDEX idx_community_invite_code_guild ON community_guild_invite_code (guild_id)');

        $this->addSql('CREATE UNIQUE INDEX uniq_community_invite_code ON community_guild_invite_code (code)');

        // La contrainte du ticket, tenue par la base : au plus un code non révoqué par
        // guilde. Doctrine ne sait pas exprimer un index partiel dans le mapping, donc il
        // vit ici et seulement ici — c'est aussi pour ça que la migration se relit.
        $this->addSql('CREATE UNIQUE INDEX uniq_community_invite_code_live ON community_guild_invite_code (guild_id) WHERE revoked_at IS NULL');

        $this->addSql('ALTER TABLE community_guild_invite_code ADD CONSTRAINT fk_community_invite_code_guild FOREIGN KEY (guild_id) REFERENCES community_guild (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild_invite_code DROP CONSTRAINT fk_community_invite_code_guild');
        $this->addSql('DROP TABLE community_guild_invite_code');
    }
}
