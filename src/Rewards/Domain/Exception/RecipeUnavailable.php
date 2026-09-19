<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

final class RecipeUnavailable extends RuleViolationError
{
    public function __construct(string $key)
    {
        parent::__construct('Cette recette est indisponible.', ['recipeKey' => $key]);
    }

    public function type(): string
    {
        return 'recipe-unavailable';
    }
}
