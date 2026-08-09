<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table des comptes joueurs.
 */
final class Version20260809172541 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée identity_user : le compte joueur et son fuseau horaire.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_user (
                id UUID NOT NULL,
                email VARCHAR(180) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(40) NOT NULL,
                timezone VARCHAR(64) NOT NULL,
                registered_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // L'unicité est garantie par la base, pas seulement par le code : deux
        // inscriptions simultanées sur la même adresse doivent en perdre une.
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_user_email ON identity_user (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE identity_user');
    }
}
