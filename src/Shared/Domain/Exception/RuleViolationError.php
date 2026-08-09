<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * La requête est bien formée mais une règle de jeu la refuse : durée hors bornes,
 * plafond quotidien atteint, cooldown non écoulé.
 */
abstract class RuleViolationError extends DomainError
{
}
