<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

final class ItemNotSellable extends RuleViolationError
{
    public function __construct(string $itemKey)
    {
        parent::__construct('Cet objet ne peut pas être vendu.', ['itemKey' => $itemKey]);
    }

    public function type(): string
    {
        return 'item-not-sellable';
    }
}
