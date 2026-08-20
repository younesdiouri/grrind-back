<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `identity_user_device.family_id` — la famille de refresh tokens qui a fait le dernier
 * `claim()` de la ligne (#136, arbitrage B). Voir le docblock de
 * {@see \App\Identity\Domain\UserDevice}.
 *
 * **Amputée à la main**, même geste que les migrations précédentes : le diff proposait aussi
 * de supprimer l'index partiel `uniq_community_invite_code_live` et la contrainte composite
 * `fk_player_active_title_unlocked` (avec son index `IDX_2D369A0FA76ED395A9F87BD`) — trois
 * écritures que Doctrine ne sait pas relire depuis le mapping et croit donc en trop à chaque
 * génération, sans aucun rapport avec ce ticket. Seules les deux lignes qui touchent
 * `identity_user_device` viennent d'ici.
 *
 * **`NULL` sans défaut, jamais `NOT NULL`.** Les lignes déjà en base n'ont pas de famille —
 * la fonctionnalité n'existait pas avant ce ticket — et un `NOT NULL` sans défaut échouerait
 * sur une table peuplée. `UserDevice::claim()` accepte `null` explicitement pour la même
 * raison côté application.
 */
final class Version20260820115104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity : identity_user_device.family_id, la famille de refresh tokens de l\'appareil (#136)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user_device ADD family_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_identity_user_device_family ON identity_user_device (family_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_identity_user_device_family');
        $this->addSql('ALTER TABLE identity_user_device DROP family_id');
    }
}
