<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

/**
 * Ce joueur n'existe pas, **ou l'appelant n'a rien à voir avec lui**. Une seule erreur pour
 * les deux, exactement comme {@see GuildNotFound}.
 *
 * **404 et jamais 403, et ici l'enjeu est plus grand qu'ailleurs.** Un 403 confirmerait
 * qu'un compte porte cet UUID, et l'API deviendrait un test d'existence sur des UUID v7 —
 * qui encodent leur instant de création, donc se devinent par plage temporelle. Un
 * attaquant pourrait énumérer les comptes ouverts un jour donné. C'est le même
 * raisonnement que la protection contre l'énumération de comptes de `json_login`, appliqué
 * à un autre identifiant.
 */
final class PlayerNotFound extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Ce joueur n\'existe pas.');
    }

    public function type(): string
    {
        return 'player-not-found';
    }
}
