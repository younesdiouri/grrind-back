<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Exception\RuleViolationError;

/**
 * La discipline demandée ne rapporte pas d'XP, donc la Risāla ne promettrait rien.
 *
 * Ce n'est pas une chicane de validation : le bonus d'une Risāla est un pourcentage du
 * socle, et le socle de `WALKING` est nul par conception (#181). Un défi « +150 % » qui
 * rapporte zéro est une promesse que le produit ne tient pas, et le joueur ne peut pas le
 * deviner depuis l'écran.
 */
final class DisciplineDoesNotCredit extends RuleViolationError
{
    public function __construct(Discipline $discipline)
    {
        parent::__construct(
            \sprintf('"%s" ne rapporte pas d\'XP : une Risāla sur cette discipline ne promettrait rien.', $discipline->value),
            ['discipline' => $discipline->value],
        );
    }

    public function type(): string
    {
        return 'discipline-does-not-credit';
    }
}
