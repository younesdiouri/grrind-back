<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Progression atteste le crédit effectif sans exposer son ledger à Training.
 * Un crédit à zéro XP compte ; une source invalidée ne compte plus.
 */
interface CreditedWorkoutIds
{
    /**
     * @param list<string> $workoutIds
     *
     * @return array<string, true> UUID workout → présence
     */
    public function among(array $workoutIds): array;
}
