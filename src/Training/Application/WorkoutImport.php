<?php

declare(strict_types=1);

namespace App\Training\Application;

/**
 * Ce qu'un lot a produit : ce qui est entré **avec ce que ça a rapporté**, et ce qui a été
 * écarté.
 *
 * Les deux, toujours, même vides. Un import où rien n'est crédité et où tout est écarté est
 * un **succès** — c'est ce que rend un client qui resynchronise sans rien de neuf — et le
 * distinguer d'un échec par la présence d'un champ obligerait le client à deviner.
 *
 * `imported` porte les récompenses depuis le #89, et rien ne les rend encore : c'est le
 * `SyncSummary` (#92) qui en fera une timeline animable. Les tenir dès maintenant est ce qui
 * évite de recharger l'état après le COMMIT, donc de décrire deux instants au lieu d'un.
 */
final readonly class WorkoutImport
{
    /**
     * @param list<SessionCompletion> $imported dans l'ordre chronologique, celui du crédit
     * @param list<SkippedWorkout>    $skipped  dans l'ordre chronologique lui aussi
     */
    public function __construct(
        public array $imported,
        public array $skipped,
    ) {
    }
}
