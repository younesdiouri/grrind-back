<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Refresh tokens rotatifs.
 */
final class Version20260809173353 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée identity_refresh_token : jetons à usage unique, groupés par famille.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE identity_refresh_token (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                family_id UUID NOT NULL,
                token_hash VARCHAR(64) NOT NULL,
                issued_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                consumed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id)
            )
            SQL);

        // La colonne stocke un SHA-256, jamais le jeton lui-même : l'index unique
        // sert à la fois de lookup et de garantie de non-collision.
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_refresh_token_hash ON identity_refresh_token (token_hash)');

        // Révoquer une famille entière est le geste critique de la détection de
        // rejeu : il doit rester instantané même après des mois de rotations.
        $this->addSql('CREATE INDEX idx_identity_refresh_token_family ON identity_refresh_token (family_id)');
        $this->addSql('CREATE INDEX IDX_DC7238A2A76ED395 ON identity_refresh_token (user_id)');

        // ON DELETE CASCADE : supprimer un compte ne doit rien laisser derrière lui.
        $this->addSql(<<<'SQL'
            ALTER TABLE identity_refresh_token
                ADD CONSTRAINT FK_DC7238A2A76ED395
                FOREIGN KEY (user_id) REFERENCES identity_user (id)
                ON DELETE CASCADE NOT DEFERRABLE
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_refresh_token DROP CONSTRAINT FK_DC7238A2A76ED395');
        $this->addSql('DROP TABLE identity_refresh_token');
    }
}
