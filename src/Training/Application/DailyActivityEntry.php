<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\WorkoutSource;
use DateTimeImmutable;

/**
 * Une journée candidate à l'upsert, telle que le métier la consomme — le pendant de
 * {@see ImportedWorkout} pour l'énergie active quotidienne (#165).
 *
 * `day` est déjà une date civile à ce stade, sans heure : c'est le contrôleur qui l'a
 * dénormalisée depuis la chaîne `AAAA-MM-JJ` envoyée par le client, voir le docblock de
 * `DailyActivityEntryRequest` pour pourquoi cette date-là ne se recalcule pas côté serveur.
 */
final readonly class DailyActivityEntry
{
    public function __construct(
        public DateTimeImmutable $day,
        public int $activeEnergyKcal,
        public WorkoutSource $source,
    ) {
    }
}
