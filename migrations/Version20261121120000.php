<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les pièces jointes audio au chat privé (#359).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild_message ADD audio_key VARCHAR(100) DEFAULT NULL, ADD audio_duration_ms INT DEFAULT NULL');
        $this->addSql('ALTER TABLE community_guild_message ADD CONSTRAINT guild_message_audio CHECK ((audio_key IS NULL AND audio_duration_ms IS NULL) OR (audio_key IS NOT NULL AND image_key IS NULL AND audio_duration_ms IS NOT NULL AND audio_duration_ms BETWEEN 1 AND 60000))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_guild_message DROP audio_key, DROP audio_duration_ms');
    }
}
