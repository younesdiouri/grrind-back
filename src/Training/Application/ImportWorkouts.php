<?php

declare(strict_types=1);

namespace App\Training\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Créditer ce qu'un fournisseur santé a enregistré pour un joueur.
 *
 * L'identifiant du joueur vient du jeton, jamais du corps : aucune route ne prend
 * d'identifiant de compte en paramètre.
 */
final readonly class ImportWorkouts
{
    /**
     * @param list<ImportedWorkout> $workouts
     */
    public function __construct(
        public Uuid $userId,
        public array $workouts,
    ) {
    }
}
