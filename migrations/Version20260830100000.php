<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'inventaire et l'équipement (#29) : une ligne par (joueur, objet), avec sa quantité, sa
 * provenance et l'emplacement où l'objet est porté — voir le docblock de
 * `App\Rewards\Domain\InventoryItem`.
 *
 * **Une seule table plutôt qu'inventaire et équipement séparés.** L'unicité partielle
 * `uniq_rewards_inventory_item_equipped_slot` — `(user_id, slot) WHERE slot IS NOT NULL` —
 * tient à elle seule « un objet par emplacement » : c'est la base qui refuse un second objet
 * dans le même emplacement, pas une vérification applicative qu'une course entre deux
 * transactions pourrait contourner.
 *
 * `user_id` et `loot_roll_id` sont des UUID nus, sans clé étrangère — même choix et mêmes
 * raisons que `rewards_coin_transaction` et `rewards_loot_roll` : `Rewards` ne connaît pas
 * `Identity`, et rien dans ce module ne charge jamais `InventoryItem` et `LootRoll` ensemble
 * par une relation Doctrine — voir le docblock de la classe.
 */
final class Version20260830100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rewards : l\'inventaire et l\'équipement (#29)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE rewards_inventory_item (id UUID NOT NULL, user_id UUID NOT NULL, item_key VARCHAR(64) NOT NULL, quantity INT NOT NULL, slot VARCHAR(16) DEFAULT NULL, loot_roll_id UUID NOT NULL, obtained_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_rewards_inventory_item_player_item ON rewards_inventory_item (user_id, item_key)');
        $this->addSql('CREATE UNIQUE INDEX uniq_rewards_inventory_item_equipped_slot ON rewards_inventory_item (user_id, slot) WHERE (slot IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rewards_inventory_item');
    }
}
