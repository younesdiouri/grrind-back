<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #160 : `progression_snapshot` porte désormais les quatre caractéristiques, recopiées
 * telles quelles du ledger — quatre colonnes signées, non nulles.
 *
 * **Même piège qu'à `Version20260825120000`, conclusion différente.** Quatre colonnes
 * `NOT NULL` sans défaut échoueraient net sur une table déjà peuplée, donc le même chemin :
 * un défaut `0` posé le temps de la migration, puis retiré aussitôt — le mapping ne déclare
 * aucun défaut, pour ne pas faire diverger le prochain `doctrine:migrations:diff`.
 *
 * Mais ici le `0/0/0/0` transitoire **n'est pas une sentinelle définitive** comme au ledger :
 * `progression_snapshot` est un cache reconstructible, pas une écriture historique qu'on ne
 * peut plus corriger après coup. `app:progression:rebuild` (#20), lancé une fois ce
 * déploiement en place, rejoue `XpTransactionRepository::attributeTotalsOf()` sur chaque
 * ligne et remplace ces zéros par la vraie somme du ledger — c'est exactement à ça que la
 * commande sert. Aucune ligne n'est donc durablement fausse, seulement le temps d'une passe
 * planifiée après le déploiement.
 */
final class Version20260825130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : progression_snapshot porte les quatre caractéristiques (#160)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_snapshot ADD strength INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE progression_snapshot ADD endurance INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE progression_snapshot ADD mobility INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE progression_snapshot ADD dexterity INT NOT NULL DEFAULT 0');

        $this->addSql('ALTER TABLE progression_snapshot ALTER COLUMN strength DROP DEFAULT');
        $this->addSql('ALTER TABLE progression_snapshot ALTER COLUMN endurance DROP DEFAULT');
        $this->addSql('ALTER TABLE progression_snapshot ALTER COLUMN mobility DROP DEFAULT');
        $this->addSql('ALTER TABLE progression_snapshot ALTER COLUMN dexterity DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_snapshot DROP dexterity');
        $this->addSql('ALTER TABLE progression_snapshot DROP mobility');
        $this->addSql('ALTER TABLE progression_snapshot DROP endurance');
        $this->addSql('ALTER TABLE progression_snapshot DROP strength');
    }
}
