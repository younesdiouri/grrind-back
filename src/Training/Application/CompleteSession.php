<?php

declare(strict_types=1);

namespace App\Training\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Tout ce que le serveur accepte pour clore une séance : qui, et laquelle. Ni `endedAt`
 * ni `durationSeconds` ne sont des paramètres — le *quand* vient de l'horloge serveur et
 * la durée s'en déduit, sinon déclarer trois heures de course coûte une ligne de JSON.
 *
 * L'auteur en fait partie et n'est pas une simple vérification a posteriori : c'est lui
 * qui restreint la recherche, de sorte qu'aucun chemin de code ne charge la séance d'un
 * autre compte avant de se demander s'il en avait le droit.
 */
final readonly class CompleteSession
{
    public function __construct(
        public Uuid $userId,
        public Uuid $sessionId,
    ) {
    }
}
