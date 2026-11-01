<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

enum LeagueTier: string
{
    case Bronze = 'BRONZE';
    case Silver = 'SILVER';
    case Gold = 'GOLD';
    case Platinum = 'PLATINUM';
    case Diamond = 'DIAMOND';

    public function order(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    public function shift(int $step): self
    {
        return self::cases()[max(0, min(4, $this->order() + $step))];
    }
}
