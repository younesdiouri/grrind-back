<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\XpAward;
use App\Shared\Domain\Activity\Discipline;

final readonly class WeekTrainingSession
{
    public function __construct(
        public Discipline $discipline,
        public int $durationSeconds,
        public XpAward $award,
    ) {
    }
}
