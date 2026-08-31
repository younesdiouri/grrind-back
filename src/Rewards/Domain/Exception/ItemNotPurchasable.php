<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * `$itemKey` ne peut pas s'acheter — soit qu'elle ne désigne aucun objet du catalogue, soit
 * qu'elle en désigne un que `shop.available` ne liste pas à l'étal (#229). Un seul refus pour
 * les deux cas, même raisonnement qu'{@see ItemNotOwned} pour l'équipement : un objet hors
 * étal n'est pas une ressource qu'on cache au joueur, ce n'est pas au catalogue de mentir sur
 * ce qu'il vend — la question du 404 ne se pose donc pas plus ici que là-bas.
 */
final class ItemNotPurchasable extends RuleViolationError
{
    public function __construct(string $itemKey)
    {
        parent::__construct(
            \sprintf('"%s" n\'est pas à l\'étal de la boutique.', $itemKey),
            ['itemKey' => $itemKey],
        );
    }

    public function type(): string
    {
        return 'item-not-purchasable';
    }
}
