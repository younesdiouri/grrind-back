<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Shared\Domain\Activity\AttributeGains;
use DateTimeImmutable;

final readonly class WeekTrainingPlan
{
    /** @param non-empty-list<WeekTrainingSession> $sessions */
    public function __construct(
        public AttributeGains $remaining,
        public AttributeGains $remainingAfter,
        public array $sessions,
        public DateTimeImmutable $calculatedAt,
        public DateTimeImmutable $expiresAt,
        public string $rulesetVersion,
    ) {
    }
}
