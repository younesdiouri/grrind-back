<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\Item;

/**
 * Ce qu'un achat a produit (#229) : l'objet du catalogue, ce qu'il a coûté, et le solde de
 * pièces avant *et* après — même geste qu'un `SessionDrop` avec ses deux paliers, pour que la
 * bourse se décrémente à l'écran sans que le client recalcule quoi que ce soit.
 */
final readonly class PurchaseReceipt
{
    public function __construct(
        public Item $item,
        public int $spentCoins,
        public int $coinsBefore,
        public int $coinsAfter,
    ) {
    }
}
