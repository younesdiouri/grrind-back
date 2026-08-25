<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #161 : `progression_snapshot` porte désormais Vitality, dérivée des quatre
 * caractéristiques — une colonne, non nulle, jamais alimentée par le ledger.
 *
 * **Même piège, même chemin qu'à `Version20260825130000`.** Une colonne `NOT NULL` sans
 * défaut échouerait net sur une table déjà peuplée : un défaut `0` posé le temps de la
 * migration, puis retiré aussitôt — le mapping ne déclare aucun défaut, pour ne pas faire
 * diverger le prochain `doctrine:migrations:diff`.
 *
 * Le `0` transitoire n'est pas non plus une sentinelle définitive : `progression_snapshot`
 * reste un cache reconstructible, et `app:progression:rebuild` (#20), lancé une fois ce
 * déploiement en place, rejoue `Vitality::of()` sur les quatre caractéristiques déjà
 * présentes en base et remplace ces zéros par la vraie valeur dérivée. Aucune ligne n'est
 * donc durablement fausse, seulement le temps d'une passe planifiée après le déploiement.
 */
final class Version20260825140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Progression : progression_snapshot porte Vitality, dérivée des quatre caractéristiques (#161)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_snapshot ADD vitality INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE progression_snapshot ALTER COLUMN vitality DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE progression_snapshot DROP vitality');
    }
}
