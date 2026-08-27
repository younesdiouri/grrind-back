<?php

declare(strict_types=1);

namespace App\Training\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Un lot de journées à réviser — le pendant de {@see ImportWorkouts} pour l'énergie active
 * (#165), en dehors de la synchro d'import.
 *
 * Un lot et non une journée seule pour la même raison qu'à l'import : un client qui revient
 * après une semaine d'absence a une semaine de journées à rattraper, pas une.
 */
final readonly class UpsertDailyActivity
{
    /**
     * @param list<DailyActivityEntry> $entries
     */
    public function __construct(
        public Uuid $userId,
        public array $entries,
    ) {
    }
}
