<?php

declare(strict_types=1);

namespace App\Combat\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

/**
 * Ce combat n'existe pas, **ou l'appelant n'est pas celui qui l'a mené**. Une seule erreur
 * pour les deux, exactement comme {@see \App\Community\Domain\Exception\PlayerNotFound}.
 *
 * **404 et jamais 403.** Un 403 confirmerait qu'un combat porte cet UUID, et les UUID v7
 * encodent leur instant de création — l'API deviendrait un moyen d'énumérer les combats
 * joués un jour donné.
 */
final class BattleNotFound extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Ce combat n\'existe pas.');
    }

    public function type(): string
    {
        return 'battle-not-found';
    }
}
