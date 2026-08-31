<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Le joueur n'a pas le niveau que `shop.minimum_level` exige pour cet objet (#229) — le
 * pendant, côté boutique, d'{@see \App\Combat\Domain\Exception\EnemyLevelTooLow}. L'objet
 * existe et il est bien à l'étal, c'est le niveau du joueur qui ne suit pas encore : une règle
 * de jeu, pas un problème d'autorisation, d'où le 422 plutôt qu'un 403.
 */
final class ShopLevelTooLow extends RuleViolationError
{
    public function __construct(string $itemKey, int $requiredLevel, int $playerLevel)
    {
        parent::__construct(
            \sprintf('"%s" exige le niveau %d, ce joueur est niveau %d.', $itemKey, $requiredLevel, $playerLevel),
            ['itemKey' => $itemKey, 'requiredLevel' => $requiredLevel, 'playerLevel' => $playerLevel],
        );
    }

    public function type(): string
    {
        return 'shop-level-too-low';
    }
}
