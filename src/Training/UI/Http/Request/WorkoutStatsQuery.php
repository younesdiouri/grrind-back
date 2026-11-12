<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use App\Training\Domain\StatsPeriod;

final readonly class WorkoutStatsQuery
{
    public function __construct(public StatsPeriod $period = StatsPeriod::Week)
    {
    }
}
