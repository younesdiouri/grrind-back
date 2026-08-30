<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\InventoryItem;

/**
 * Ce que `GET /api/inventory` (#30) rend d'un joueur : le sac, la doublure équipée, la
 * bourse — les trois lectures que l'écran assemble en un seul aller-retour.
 *
 * `$items` porte **tout** ce que le joueur possède, équipé compris — voir le docblock
 * d'{@see \App\Rewards\Infrastructure\Doctrine\InventoryItemRepository::ownedByPlayer()}.
 * `$equipmentBySlot` n'en est pas une seconde source : c'est la même donnée, simplement
 * réindexée par emplacement pour la vue « poupée d'équipement », que le client n'a pas à
 * reconstruire lui-même en filtrant `$items` sur leur `slot()`.
 */
final readonly class InventoryOverview
{
    /**
     * @param list<InventoryItem>               $items
     * @param array<string, InventoryItem|null> $equipmentBySlot une valeur d'`EquipmentSlot` par clé, `null` pour un emplacement vide
     */
    public function __construct(
        public array $items,
        public array $equipmentBySlot,
        public int $coins,
    ) {
    }
}
