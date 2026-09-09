<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use Symfony\Component\Uid\Uuid;

final readonly class SellItem
{
    public function __construct(public Uuid $userId, public string $itemKey, public int $expectedSellPriceCoins)
    {
    }
}
