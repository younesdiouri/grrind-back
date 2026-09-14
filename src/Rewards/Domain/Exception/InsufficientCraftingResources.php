<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

final class InsufficientCraftingResources extends RuleViolationError
{
    public function __construct(string $key)
    {
        parent::__construct('Les ressources possédées ne suffisent pas pour fabriquer cet équipement.', ['itemKey' => $key]);
    }

    public function type(): string
    {
        return 'insufficient-crafting-resources';
    }
}
