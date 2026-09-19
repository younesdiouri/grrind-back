<?php

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Activity\Discipline;
use DateTimeImmutable;

/** Mesures minimales du fournisseur nécessaires au recalcul, sans santé ni localisation. */
final readonly class AlamEffort
{
    public function __construct(public string $sourceId, public Discipline $discipline, public DateTimeImmutable $start, public DateTimeImmutable $end, public int $seconds, public ?int $distance, public ?int $elevation)
    {
    }
}
