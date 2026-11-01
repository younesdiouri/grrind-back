<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;

/** Le ledger appartient à Progression ; Engagement ne connaît que ses scores nets hebdomadaires. */
interface LeagueScores
{
    /** @return array<string, int> scores indexés par UUID ; fenêtre sportive [start, end[ */
    public function between(DateTimeImmutable $start, DateTimeImmutable $end): array;
}
