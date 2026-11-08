<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\AttributeGains;

/**
 * Ce qu'une semaine de caractéristiques a rapporté (#348) : l'XP, les séances créditées
 * nettes des annulations, et la répartition par caractéristique. Mêmes sommes que les
 * `attributes` des écritures qu'elle couvre.
 */
final readonly class WeekTotals
{
    public function __construct(
        public int $xp,
        public int $sessions,
        public AttributeGains $attributes,
    ) {
    }
}
