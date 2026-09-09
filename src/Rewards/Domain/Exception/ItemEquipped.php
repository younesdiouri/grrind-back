<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

final class ItemEquipped extends RuleViolationError
{
    public function __construct(string $itemKey)
    {
        parent::__construct('Retirez le dernier exemplaire équipé avant de le vendre.', ['itemKey' => $itemKey]);
    }

    public function type(): string
    {
        return 'item-equipped';
    }
}
