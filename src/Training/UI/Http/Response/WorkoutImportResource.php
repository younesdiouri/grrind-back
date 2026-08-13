<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Application\SessionCompletion;
use App\Training\Application\SkippedWorkout;
use App\Training\Application\WorkoutImport;

/**
 * Ce qu'un import rend au client : ce qui est entré, puis ce qui a été écarté.
 *
 * **L'ordre des clés est l'ordre de la lecture**, comme pour le `RewardSummary` : le joueur
 * voit d'abord ce qu'il a gagné, ensuite ce qui n'a pas compté. C'est aussi pour ça que les
 * deux tableaux sont toujours présents même vides — un champ qui apparaît et disparaît finit
 * lu de travers.
 *
 * Forme **provisoire**. Le #92 la remplace par un `SyncSummary` : les workouts crédités y
 * deviendront une timeline animable portant l'XP, les niveaux et le streak. `skipped` y
 * survivra tel quel — c'est déjà sa forme définitive.
 */
final readonly class WorkoutImportResource
{
    /**
     * @param list<TrainingSessionResource>                                         $imported
     * @param list<array{externalId: string, activityType: string, reason: string}> $skipped
     */
    public function __construct(
        public array $imported,
        public array $skipped,
    ) {
    }

    public static function from(WorkoutImport $import): self
    {
        return new self(
            // Seule la séance est rendue, pas encore sa récompense : le lot les tient déjà
            // toutes (`SessionCompletion`), et c'est le `SyncSummary` (#92) qui en fera la
            // timeline animable. Les publier ici sous une forme provisoire imposerait au
            // client de décoder deux contrats successifs pour le même écran.
            array_map(
                static fn (SessionCompletion $credited): TrainingSessionResource => TrainingSessionResource::from($credited->session),
                $import->imported,
            ),
            array_map(
                static fn (SkippedWorkout $skipped): array => [
                    'externalId' => $skipped->externalId,
                    'activityType' => $skipped->activityType,
                    'reason' => $skipped->reason->value,
                ],
                $import->skipped,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'imported' => array_map(static fn (TrainingSessionResource $workout): array => $workout->toArray(), $this->imported),
            'skipped' => $this->skipped,
        ];
    }
}
