<?php

declare(strict_types=1);

namespace App\Rewards\Application;

/**
 * Ce que `GET /api/shop` (#229) rend d'un joueur : l'étal, et la bourse — même geste
 * qu'{@see InventoryOverview} pour le sac. Le solde de pièces figure ici comme sur
 * `GET /api/inventory` : c'est le même écran de bourse, il n'a pas à faire un second appel
 * pour savoir s'il peut payer.
 */
final readonly class ShopOverview
{
    /**
     * @param list<ShopEntry> $entries
     */
    public function __construct(
        public array $entries,
        public int $coins,
    ) {
    }
}
