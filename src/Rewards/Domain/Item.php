<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Un objet du catalogue — pas une ligne d'inventaire : rien ici n'appartient à un joueur,
 * voir le docblock d'{@see ItemCatalog}.
 */
final readonly class Item
{
    /**
     * @param list<ItemModifier> $modifiers
     */
    public function __construct(
        public string $key,
        public Rarity $rarity,
        public EquipmentSlot $slot,
        /** En pièces — voir le docblock d'`items.yaml` pour pourquoi il existe avant la boutique (Lot 6b). */
        public int $priceCoins,
        public array $modifiers,
    ) {
    }
}
