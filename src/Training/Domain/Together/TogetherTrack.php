<?php

declare(strict_types=1);

namespace App\Training\Domain\Together;

final readonly class TogetherTrack
{
    /** @param list<array{at: int, latitude: float, longitude: float, accuracy: float}> $points triés par instant */
    public function __construct(public string $id, public string $user, public string $sport, public int $start, public int $end, public array $points)
    {
    }
}
