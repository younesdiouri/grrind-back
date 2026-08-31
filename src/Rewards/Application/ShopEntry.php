<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\Item;

/**
 * Un objet de l'étal, augmenté de ce que **ce joueur** en sait (#229) — voir le docblock
 * d'{@see ShopOverview} pour pourquoi c'est calculé ici plutôt que dans la ressource HTTP.
 */
final readonly class ShopEntry
{
    public function __construct(
        public Item $item,
        /** Le solde actuel du joueur couvre `$item->priceCoins`. */
        public bool $affordable,
        /** Déjà possédé — l'achat serait refusé (`item-already-owned`), voir {@see Inventory::purchase()}. */
        public bool $owned,
        /** Le joueur a atteint `$item->shopMinimumLevel`. */
        public bool $unlocked,
    ) {
    }
}
