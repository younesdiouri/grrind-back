<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'historique appartient à la guilde ; sa dissolution supprime les messages en cascade.
 * Les fichiers privés sans référence sont repris par app:chat:cleanup sur la machine web.
 */
final class Version20260909152101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le chat privé des guildes (#275).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE community_guild_message (id UUID NOT NULL, author_id UUID NOT NULL, client_id UUID NOT NULL, position INT NOT NULL, text TEXT NOT NULL, fingerprint VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, image_key VARCHAR(100) DEFAULT NULL, guild_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_E6E8618D5F2131EF ON community_guild_message (guild_id)');
        $this->addSql('CREATE UNIQUE INDEX guild_message_position ON community_guild_message (guild_id, position)');
        $this->addSql('CREATE UNIQUE INDEX guild_message_client ON community_guild_message (guild_id, author_id, client_id)');
        $this->addSql('ALTER TABLE community_guild_message ADD CONSTRAINT FK_E6E8618D5F2131EF FOREIGN KEY (guild_id) REFERENCES community_guild (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild_message DROP CONSTRAINT FK_E6E8618D5F2131EF');
        $this->addSql('DROP TABLE community_guild_message');
    }
}
