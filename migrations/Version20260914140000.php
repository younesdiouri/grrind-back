<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Une réponse perdue après commit reste rejouable après expiration du cache HTTP. */
final class Version20260914140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserve durablement la clé de fabrication et son reçu (#279).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rewards_crafting_audit ADD request_key VARCHAR(64) DEFAULT NULL');
        $this->addSql("ALTER TABLE rewards_crafting_audit ADD receipt JSON DEFAULT '{}' NOT NULL");
        $this->addSql('ALTER TABLE rewards_crafting_audit ALTER receipt DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX uniq_crafting_request_key ON rewards_crafting_audit (request_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_crafting_request_key');
        $this->addSql('ALTER TABLE rewards_crafting_audit DROP request_key, DROP receipt');
    }
}
