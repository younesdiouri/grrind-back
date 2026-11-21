<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ferme le recrutement par défaut et conserve les décisions de candidature (#358).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild ADD recruitment_open BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('CREATE TABLE community_guild_application (id UUID PRIMARY KEY, guild_id UUID NOT NULL, guild_name VARCHAR(40) NOT NULL, player_id UUID NOT NULL, display_name VARCHAR(255) NOT NULL, status VARCHAR(255) NOT NULL)');
        $this->addSql("CREATE UNIQUE INDEX guild_application_pending ON community_guild_application (guild_id, player_id) WHERE status = 'PENDING'");
        $this->addSql('CREATE INDEX guild_application_player_history ON community_guild_application (player_id, id)');
        $this->addSql('CREATE INDEX guild_application_inbox ON community_guild_application (guild_id, status, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE community_guild_application');
        $this->addSql('ALTER TABLE community_guild DROP recruitment_open');
    }
}
