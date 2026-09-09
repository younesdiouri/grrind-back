<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

final class SalePriceChanged extends RuleViolationError
{
    public function __construct(string $itemKey)
    {
        parent::__construct('Le prix de revente a changé. Confirmez le nouveau prix.', ['itemKey' => $itemKey]);
    }

    public function type(): string
    {
        return 'sale-price-changed';
    }
}
