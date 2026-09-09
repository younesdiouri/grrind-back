<?php

declare(strict_types=1);

namespace App\Rewards\Application;

final readonly class SaleReceipt
{
    public function __construct(public string $itemKey, public int $quantity, public int $coins, public int $coinsBefore, public int $coinsAfter)
    {
    }
}
