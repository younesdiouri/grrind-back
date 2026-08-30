<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Le corps de `PUT /api/inventory/equipment/{slot}` (#30) : l'objet à y poser, et rien
 * d'autre — l'emplacement vient de l'URL, jamais du corps, sans quoi les deux pourraient se
 * contredire.
 *
 * Aucune contrainte de format au-delà de « non vide » : une clé inconnue du catalogue, ou
 * connue mais non possédée, se refuse dans le domaine — voir {@see \App\Rewards\Domain\Exception\ItemNotOwned}
 * — pas ici. Même choix que {@see \App\Combat\UI\Http\Request\FightBattleRequest} pour une
 * clé d'ennemi.
 */
final readonly class EquipItemRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $itemKey = '',
    ) {
    }
}
