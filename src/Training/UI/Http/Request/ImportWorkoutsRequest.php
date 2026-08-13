<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contrat d'entrée de `POST /api/workouts/import`.
 *
 * **Un lot, pas un workout.** Revenir après dix jours d'absence avec trois séances à
 * créditer est le cas nominal du produit, pas l'exception à traiter plus tard : le client
 * envoie tout ce qu'il a trouvé depuis sa dernière synchronisation, et une route unitaire
 * l'obligerait à faire N requêtes dont certaines échoueraient.
 *
 * L'objet enveloppe la liste au lieu d'accepter un tableau nu à la racine. C'est ce qui
 * permettra d'ajouter le curseur de synchronisation (#93) sans changer la forme du corps.
 */
final readonly class ImportWorkoutsRequest
{
    /**
     * Un lot borné, pour qu'une requête ne devienne pas une reprise d'historique de trois
     * ans en une transaction. Ça n'ampute personne : la fenêtre d'antériorité (#91) dit
     * jusqu'où on remonte, et un premier import se pagine côté client.
     */
    public const int MAX_WORKOUTS = 200;

    /**
     * @param list<ImportedWorkoutRequest> $workouts
     */
    public function __construct(
        // `Valid` est ce qui fait descendre la validation dans chaque élément ; sans lui,
        // seul le nombre serait vérifié et un `externalId` vide passerait.
        #[Assert\Valid]
        #[Assert\Count(min: 1, max: self::MAX_WORKOUTS)]
        public array $workouts = [],
    ) {
    }
}
