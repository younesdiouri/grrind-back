<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/** Une transformation publiée, sans amélioration ni monnaie implicite. */
final readonly class CraftingRecipe
{
    /** @param array<string, int> $costs */
    public function __construct(public string $key, public string $resultKey, public int $quantity, public array $costs)
    {
    }
}
