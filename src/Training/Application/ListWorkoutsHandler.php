<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\UI\Http\Cursor;
use App\Training\Domain\Workout;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;

/**
 * Découpe l'historique en pages. Une ligne de plus que demandé est lue à chaque appel : si
 * elle existe, il y a une suite, et le curseur suivant est le dernier élément **rendu**. Un
 * curseur désigne une position dans les données et non un rang — la page ne glisse donc pas
 * quand un workout s'ajoute pendant le défilement.
 */
final readonly class ListWorkoutsHandler
{
    public function __construct(private WorkoutRepository $workouts)
    {
    }

    public function __invoke(ListWorkouts $query): WorkoutPage
    {
        $found = $this->workouts->history($query, $query->limit + 1);

        if (\count($found) <= $query->limit) {
            return new WorkoutPage($found, null);
        }

        // Non vide par construction : plus de lignes que la limite, elle-même >= 1.
        /** @var non-empty-list<Workout> $page */
        $page = \array_slice($found, 0, $query->limit);
        $last = $page[array_key_last($page)];

        return new WorkoutPage($page, Cursor::of($last->startedAt(), $last->id()));
    }
}
