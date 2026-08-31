<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * `$itemKey` désigne un objet du catalogue, mais pas un coffre (#230) — un `EQUIPMENT` qu'on
 * a tenté d'ouvrir. Distincte d'{@see ItemNotOwned} : la clé existe et le joueur peut même la
 * posséder, ce n'est pas la question. Une règle de jeu, pas un problème d'autorisation, d'où
 * le 422 — même raisonnement que {@see ItemNotPurchasable}.
 */
final class ItemNotAChest extends RuleViolationError
{
    public function __construct(string $itemKey)
    {
        parent::__construct(
            \sprintf('"%s" n\'est pas un coffre.', $itemKey),
            ['itemKey' => $itemKey],
        );
    }

    public function type(): string
    {
        return 'item-not-a-chest';
    }
}
