<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

/**
 * La guilde n'existe pas, **ou l'appelant n'en fait pas partie**. Les deux cas rendent
 * la même erreur, et c'est le but : un 403 sur une guilde dont on n'est pas membre
 * confirmerait qu'une guilde porte cet UUID, et l'API deviendrait un test d'existence.
 *
 * Le 403 reste possible, mais seulement pour un membre qui existe et n'a pas le droit
 * demandé — là, l'appelant sait déjà que la guilde existe, il en est.
 */
final class GuildNotFound extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Cette guilde n\'existe pas.');
    }

    public function type(): string
    {
        return 'guild-not-found';
    }
}
