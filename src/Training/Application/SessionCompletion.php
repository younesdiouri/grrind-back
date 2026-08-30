<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Application\SessionDrop;
use App\Shared\Application\SessionReward;
use App\Training\Domain\Workout;

/**
 * Un workout crédité et ce qu'il a rapporté.
 *
 * Les trois ensemble parce qu'elles sortent de la **même** transaction. Les séparer
 * obligerait l'appelant à recharger l'une d'elles après coup, donc à lire un état qui a pu
 * bouger entre-temps — et le `RewardSummary` (#22) doit décrire un instant, pas deux.
 *
 * `reward` et `drop` sont peuplées par un module chacune — `Progression` et `Rewards`
 * (#226) — et le streak (Lot 5) s'ajoutera de la même façon, en champ voisin.
 */
final readonly class SessionCompletion
{
    public function __construct(
        public Workout $session,
        public SessionReward $reward,
        public SessionDrop $drop,
    ) {
    }
}
