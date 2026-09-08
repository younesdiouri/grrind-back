<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SellItemRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $itemKey,
        #[Assert\PositiveOrZero]
        public int $expectedSellPriceCoins,
    ) {
    }
}
