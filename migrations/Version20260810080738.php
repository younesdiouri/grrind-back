<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `identity_user` devient un UserInterface Symfony : colonne `roles`, et
 * `password_hash` renommée en `password` (le nom qu'attend
 * PasswordAuthenticatedUserInterface), désormais nullable pour les comptes créés
 * par social sign-in, qui n'ont jamais eu de mot de passe.
 *
 * Écrite à la main : le diff Doctrine proposait DROP puis ADD, ce qui aurait effacé
 * tous les hachages, et un ADD NOT NULL sans défaut qui aurait échoué sur une table
 * non vide.
 */
final class Version20260810080738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Identity : rôles Symfony, et password_hash renommée password (nullable)';
    }

    public function up(Schema $schema): void
    {
        // Défaut posé le temps de remplir les lignes existantes, puis retiré : le
        // mapping ne déclare pas de défaut, et le laisser ferait diverger le prochain
        // doctrine:migrations:diff. ROLE_USER est implicite, un tableau vide suffit.
        $this->addSql("ALTER TABLE identity_user ADD roles JSON NOT NULL DEFAULT '[]'");
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN roles DROP DEFAULT');

        // RENAME et non DROP/ADD : les hachages existants restent valides.
        $this->addSql('ALTER TABLE identity_user RENAME COLUMN password_hash TO password');
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN password DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Un compte sans mot de passe n'a pas d'équivalent dans l'ancien schéma :
        // le NOT NULL échouera s'il en existe, et c'est le bon comportement — mieux
        // vaut refuser le retour arrière que fabriquer un hachage bidon.
        $this->addSql('ALTER TABLE identity_user ALTER COLUMN password SET NOT NULL');
        $this->addSql('ALTER TABLE identity_user RENAME COLUMN password TO password_hash');
        $this->addSql('ALTER TABLE identity_user DROP roles');
    }
}
