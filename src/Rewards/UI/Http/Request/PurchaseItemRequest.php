<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le corps de `POST /api/shop/purchases` (#229) : l'objet à acheter, et rien d'autre.
 *
 * Aucune contrainte de format au-delà de « non vide » : une clé inconnue ou hors étal se
 * refuse dans le domaine — voir {@see \App\Rewards\Domain\Exception\ItemNotPurchasable} — pas
 * ici. Même choix qu'{@see EquipItemRequest} pour une clé d'objet.
 */
final readonly class PurchaseItemRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $itemKey = '',
    ) {
    }
}
