<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\Workout;

/**
 * Ce qu'un lot a produit : ce qui est entré, et ce qui a été écarté.
 *
 * Les deux, toujours, même vides. Un import où rien n'est crédité et où tout est écarté est
 * un **succès** — c'est ce que rend un client qui resynchronise sans rien de neuf — et le
 * distinguer d'un échec par la présence d'un champ obligerait le client à deviner.
 *
 * C'est le socle du `SyncSummary` (#92), qui y ajoutera l'XP, les niveaux et la mise en
 * scène. Ici il n'y a encore rien à animer : #89 branche le crédit.
 */
final readonly class WorkoutImport
{
    /**
     * @param list<Workout>        $imported
     * @param list<SkippedWorkout> $skipped
     */
    public function __construct(
        public array $imported,
        public array $skipped,
    ) {
    }
}
