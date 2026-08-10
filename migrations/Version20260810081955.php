<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table de liaison du social sign-in. La clé métier est l'unique (provider, subject),
 * pas l'adresse e-mail : le `sub` d'un fournisseur est stable, l'adresse non.
 *
 * ON DELETE CASCADE : supprimer un compte emporte ses identités liées.
 */
final class Version20260810081955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity : table identity_social_identity (Google, Apple)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_social_identity (id UUID NOT NULL, provider VARCHAR(16) NOT NULL, subject VARCHAR(255) NOT NULL, email_at_link_time VARCHAR(180) DEFAULT NULL, linked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_10C86E21A76ED395 ON identity_social_identity (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_social_provider_subject ON identity_social_identity (provider, subject)');
        $this->addSql('ALTER TABLE identity_social_identity ADD CONSTRAINT FK_10C86E21A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_social_identity DROP CONSTRAINT FK_10C86E21A76ED395');
        $this->addSql('DROP TABLE identity_social_identity');
    }
}
