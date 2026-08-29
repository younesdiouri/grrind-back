<?php

declare(strict_types=1);

namespace App\Combat\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * Le joueur a choisi un adversaire dont il n'a pas le niveau requis (#219) — un ennemi
 * ordinaire en dessous de son palier, ou un boss en dessous de son `minimum_level`. La clé
 * existe, c'est le niveau du joueur qui ne suit pas encore : une règle de jeu, pas un
 * problème d'autorisation, d'où le 422 plutôt qu'un 403.
 */
final class EnemyLevelTooLow extends RuleViolationError
{
    public function __construct(string $key, int $requiredLevel, int $playerLevel)
    {
        parent::__construct(
            \sprintf('"%s" exige le niveau %d, ce joueur est niveau %d.', $key, $requiredLevel, $playerLevel),
            ['key' => $key, 'requiredLevel' => $requiredLevel, 'playerLevel' => $playerLevel],
        );
    }

    public function type(): string
    {
        return 'enemy-level-too-low';
    }
}
