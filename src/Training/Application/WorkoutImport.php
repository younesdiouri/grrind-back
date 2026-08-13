<?php

declare(strict_types=1);

namespace App\Training\Application;

use DateTimeImmutable;

/**
 * Ce qu'un lot a produit : ce qui est entré **avec ce que ça a rapporté**, et ce qui a été
 * écarté.
 *
 * Les deux, toujours, même vides. Un import où rien n'est crédité et où tout est écarté est
 * un **succès** — c'est ce que rend un client qui resynchronise sans rien de neuf — et le
 * distinguer d'un échec par la présence d'un champ obligerait le client à deviner.
 *
 * Les récompenses sortent de la **même** transaction que les workouts, et c'est ce qui évite
 * de recharger l'état après le COMMIT : le `SyncSummary` décrit un instant, pas deux.
 */
final readonly class WorkoutImport
{
    /**
     * @param DateTimeImmutable       $syncedAt l'horloge du serveur, le seul instant de tout ce payload qui ne vienne pas du fournisseur
     * @param list<SessionCompletion> $imported dans l'ordre chronologique, celui du crédit et celui de l'animation
     * @param list<SkippedWorkout>    $skipped  dans l'ordre chronologique lui aussi
     */
    public function __construct(
        public DateTimeImmutable $syncedAt,
        public array $imported,
        public array $skipped,
    ) {
    }
}
