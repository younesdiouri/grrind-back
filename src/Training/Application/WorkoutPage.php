<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\UI\Http\Cursor;
use App\Training\Domain\Workout;

/**
 * Une tranche d'historique et de quoi demander la suivante. Pas de total : un `COUNT(*)`
 * par page pour une information dont un défilement infini n'a aucun usage. Le client est au
 * bout quand `nextCursor` est `null`.
 */
final readonly class WorkoutPage
{
    /**
     * @param list<Workout> $workouts
     */
    public function __construct(
        public array $workouts,
        public ?Cursor $nextCursor,
    ) {
    }
}
