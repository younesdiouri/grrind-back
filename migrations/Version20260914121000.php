<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260914121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Idempotence durable des éditions manuelles après interruption HTTP (#279)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE community_alam_run ADD COLUMN IF NOT EXISTS request_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_alam_request ON community_alam_run (request_key)');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Préserve les clés des éditions récompensées.');
    }
}
