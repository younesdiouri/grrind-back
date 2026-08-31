<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Ouvrir un coffre possédé (#230). `$chestKey` est une chaîne brute, jamais un
 * {@see \App\Rewards\Domain\Item} déjà résolu — même choix que {@see PurchaseItem} et
 * {@see EquipItem} pour une clé d'objet : la résolution et ses refus (`item-not-owned`,
 * `item-not-a-chest`) sont des règles de domaine, pas un détail du transport.
 *
 * Désigne la clé du coffre, pas un exemplaire : une ligne d'inventaire est une pile, pas une
 * collection d'identités propres — voir le docblock d'{@see \App\Rewards\Domain\InventoryItem}.
 */
final readonly class OpenChest
{
    public function __construct(
        public Uuid $userId,
        public string $chestKey,
    ) {
    }
}
