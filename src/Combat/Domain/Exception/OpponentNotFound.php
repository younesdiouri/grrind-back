<?php

declare(strict_types=1);

namespace App\Combat\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

/**
 * Ce joueur n'existe pas, **ou n'est pas un co-équipier du défieur**. Une seule erreur pour
 * les deux, exactement comme {@see \App\Community\Domain\Exception\PlayerNotFound}, dont
 * c'est le pendant côté combat.
 *
 * **404 et jamais 403.** Un 403 confirmerait qu'un compte porte cet UUID, et les UUID v7
 * encodent leur instant de création — l'API deviendrait un moyen d'énumérer les comptes
 * ouverts un jour donné. Un identifiant malformé rend la même chose : il n'a jamais désigné
 * un joueur, et un 400 renseignerait sur la forme attendue.
 */
final class OpponentNotFound extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Ce joueur n\'existe pas.');
    }

    public function type(): string
    {
        return 'opponent-not-found';
    }
}
