<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;

/**
 * Un joueur n'appartient qu'à une guilde. C'est `uniq_community_membership_player` qui
 * le garantit — cette exception est la traduction métier de la violation d'index, pas
 * une vérification qui la précéderait.
 */
final class PlayerAlreadyInAGuild extends ConflictError
{
    public function __construct()
    {
        parent::__construct('Ce joueur appartient déjà à une guilde.');
    }

    public function type(): string
    {
        return 'player-already-in-a-guild';
    }
}
