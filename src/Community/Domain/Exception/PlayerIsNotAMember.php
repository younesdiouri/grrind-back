<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

/**
 * Le joueur visé n'est pas dans cette guilde.
 *
 * Rien à cacher ici, contrairement à {@see GuildNotFound} : le seul appelant est le
 * fondateur en train d'exclure quelqu'un, et il a la liste de ses membres sous les yeux.
 * L'erreur est donc explicite — elle dit ce qui s'est passé, généralement que l'exclusion
 * est arrivée après un départ volontaire.
 */
final class PlayerIsNotAMember extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Ce joueur n\'est pas membre de cette guilde.');
    }

    public function type(): string
    {
        return 'player-is-not-a-member';
    }
}
