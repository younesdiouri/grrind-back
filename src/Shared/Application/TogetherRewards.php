<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/** Training prouve la coprésence ; Progression seul écrit le complément au ledger. */
interface TogetherRewards
{
    public function grant(Uuid $workoutId, int $multiplier, string $detectionVersion): void;
}
