<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La boutique (#229) : `rewards_inventory_item.loot_roll_id` devient nullable. `null` veut
 * dire « acquis autrement qu'au tirage » — un achat, aujourd'hui la seule autre voie — voir
 * le docblock d'`App\Rewards\Domain\InventoryItem`.
 *
 * **Migration destructive assumée.** `down()` ne peut pas rétablir `NOT NULL` sans risque une
 * fois qu'un achat a écrit une ligne à `null` : Postgres refuserait la contrainte sur une
 * table qui contient déjà la valeur qu'elle interdirait. Grrind n'est pas déployé et n'a pas
 * de joueur réel (voir `CLAUDE.md`, « Où en est le produit ») — aucune ligne à perdre
 * aujourd'hui, seulement des lignes de test jetables — donc `down()` réapplique la contrainte
 * telle quelle plutôt que de nettoyer les lignes qui la violeraient : ce n'est plus vrai dès
 * qu'un achat existe pour de bon, et cette décision saute au premier joueur réel.
 */
final class Version20260831090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewards : loot_roll_id devient nullable — la boutique achète sans tirage (#229)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rewards_inventory_item ALTER COLUMN loot_roll_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE rewards_inventory_item ALTER COLUMN loot_roll_id SET NOT NULL');
    }
}
