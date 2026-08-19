<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Préférences de notification par catégorie (#132) : `identity_user` gagne
 * `disabled_notification_categories`, présence = coupée — même convention que
 * `roles` (#Version20260810080738), pour que le défaut à l'inscription (« activé »)
 * n'exige aucun backfill quand une catégorie de plus s'ajoute au catalogue.
 *
 * Écrite à la main : le diff Doctrine mêlait cet ajout à de la dérive de schéma sans
 * rapport (index de `community_guild_invite_code`, contrainte de
 * `player_active_title`) — ni l'un ni l'autre ne fait partie de ce ticket, retirés du
 * diff généré.
 */
final class Version20260819094758 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity : préférences de notification coupées, par compte (#132)';
    }

    public function up(Schema $schema): void
    {
        // Défaut posé le temps de remplir les lignes existantes, puis retiré : le
        // mapping ne déclare pas de défaut, et le laisser ferait diverger le prochain
        // doctrine:migrations:diff. Un tableau vide veut dire « tout activé ».
        $this->addSql("ALTER TABLE identity_user ADD disabled_notification_categories JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN disabled_notification_categories DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE identity_user DROP disabled_notification_categories');
    }
}
