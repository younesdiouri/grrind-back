<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le registre des appareils joignables par notification push (#129).
 *
 * **Amputée à la main, comme les précédentes.** Le diff proposait aussi de `DROP` puis
 * `ADD` la même contrainte composite sur `player_active_title`
 * (`fk_player_active_title_unlocked` / `IDX_2D369A0FA76ED395A9F87BD`) et de supprimer
 * `uniq_community_invite_code_live` — deux artefacts qu'il ne sait pas relire depuis le
 * mapping (une contrainte composite pour l'un, un index partiel `WHERE revoked_at IS NULL`
 * pour l'autre), sans aucun rapport avec ce ticket. Seules les trois lignes qui créent
 * `identity_user_device` viennent d'ici.
 *
 * L'unicité porte sur `push_token` seul, jamais sur `(user_id, push_token)` : c'est ce qui
 * fait tenir « le jeton appartient à l'appareil, pas au compte » dans la base — voir le
 * docblock de {@see \App\Identity\Domain\UserDevice}.
 */
final class Version20260819084411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity : identity_user_device, le registre des appareils joignables (#129)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE identity_user_device (id UUID NOT NULL, push_token VARCHAR(255) NOT NULL, platform VARCHAR(16) NOT NULL, environment VARCHAR(16) NOT NULL, registered_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_identity_user_device_user ON identity_user_device (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_identity_user_device_token ON identity_user_device (push_token)');
        $this->addSql('ALTER TABLE identity_user_device ADD CONSTRAINT FK_D3D52997A76ED395 FOREIGN KEY (user_id) REFERENCES identity_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user_device DROP CONSTRAINT FK_D3D52997A76ED395');
        $this->addSql('DROP TABLE identity_user_device');
    }
}
