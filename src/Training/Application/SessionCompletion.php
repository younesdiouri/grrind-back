<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Application\SessionReward;
use App\Training\Domain\Workout;

/**
 * Un workout crédité et ce qu'il a rapporté.
 *
 * Les deux ensemble parce qu'elles sortent de la **même** transaction. Les séparer
 * obligerait l'appelant à recharger l'une des deux après coup, donc à lire un état qui a pu
 * bouger entre-temps — et le `RewardSummary` (#22) doit décrire un instant, pas deux.
 *
 * `reward` restera peuplée d'un module à la fois : `Progression` aujourd'hui, le loot au
 * Lot 6, le streak au Lot 5. C'est ici qu'ils s'ajouteront, en champs voisins.
 */
final readonly class SessionCompletion
{
    public function __construct(
        public Workout $session,
        public SessionReward $reward,
    ) {
    }
}
