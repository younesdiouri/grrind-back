<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #159 : le ledger porte désormais la répartition d'un montant sur les quatre
 * caractéristiques du personnage — quatre colonnes signées, non nulles, sur
 * `xp_transaction`.
 *
 * **Pas de backfill, et le choix qui en découle.** Aucune transaction déjà écrite ne porte
 * de répartition qu'on saurait reconstituer honnêtement : `AttributeSplit` n'existait pas
 * quand ces lignes ont été calculées, et lui faire rejouer un calcul après coup fabriquerait
 * un vecteur que personne n'a réellement accordé — pire que de dire franchement qu'on ne
 * sait pas. Mais quatre colonnes `NOT NULL` sans défaut échoueraient net sur une table déjà
 * peuplée, et la base de production n'est pas la base de dev jetable.
 *
 * Le chemin retenu, déjà vu sur `identity_user.roles` (Version20260810080738) : un défaut
 * `0` posé le temps de la migration, puis retiré aussitôt — le mapping ne déclare aucun
 * défaut, et en laisser un ferait diverger le prochain `doctrine:migrations:diff`. Les
 * lignes déjà écrites reçoivent donc `0/0/0/0`, un sentinelle assumé — « pas de répartition
 * connue pour cette écriture » — et non une répartition inventée : `strength + endurance +
 * mobility + dexterity == amount` ne tient pas sur ces lignes-là, seulement sur celles
 * écrites après ce déploiement. Aucune ligne n'est ni perdue ni faussée en XP total, la
 * seule colonne qui reste vraie de bout en bout : ni `amount`, ni `duration_seconds`, ni le
 * détail du breakdown ne bougent.
 */
final class Version20260825120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : xp_transaction porte la répartition en caractéristiques (#159)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE xp_transaction ADD strength INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE xp_transaction ADD endurance INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE xp_transaction ADD mobility INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE xp_transaction ADD dexterity INT NOT NULL DEFAULT 0');

        $this->addSql('ALTER TABLE xp_transaction ALTER COLUMN strength DROP DEFAULT');
        $this->addSql('ALTER TABLE xp_transaction ALTER COLUMN endurance DROP DEFAULT');
        $this->addSql('ALTER TABLE xp_transaction ALTER COLUMN mobility DROP DEFAULT');
        $this->addSql('ALTER TABLE xp_transaction ALTER COLUMN dexterity DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE xp_transaction DROP dexterity');
        $this->addSql('ALTER TABLE xp_transaction DROP mobility');
        $this->addSql('ALTER TABLE xp_transaction DROP endurance');
        $this->addSql('ALTER TABLE xp_transaction DROP strength');
    }
}
