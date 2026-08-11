<?php

declare(strict_types=1);

namespace App\Training\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Qui, et laquelle. Ni `endedAt` ni `durationSeconds` : le *quand* vient de l'horloge
 * serveur et la durée s'en déduit, sinon déclarer trois heures de course coûte une ligne
 * de JSON.
 *
 * L'auteur n'est pas une vérification a posteriori — c'est lui qui restreint la
 * recherche, donc aucun chemin ne charge la séance d'un autre compte.
 */
final readonly class CompleteSession
{
    public function __construct(
        public Uuid $userId,
        public Uuid $sessionId,
    ) {
    }
}
