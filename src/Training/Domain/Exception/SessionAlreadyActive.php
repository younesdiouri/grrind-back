<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;
use Symfony\Component\Uid\Uuid;

/**
 * Une séance tourne déjà. C'est le cas du joueur qui a fermé l'app sans couper son
 * chronomètre et rouvre l'écran de démarrage.
 *
 * L'identifiant de la séance en cours part dans l'erreur pour qu'il puisse s'y
 * rebrancher plutôt que de rester coincé devant un refus : c'est la même séance qu'il
 * voulait, il ne le sait simplement plus.
 */
final class SessionAlreadyActive extends ConflictError
{
    public function __construct(Uuid $activeSessionId)
    {
        parent::__construct(
            'Une séance est déjà en cours.',
            ['activeSessionId' => $activeSessionId->toRfc4122()],
        );
    }

    public function type(): string
    {
        return 'session-already-active';
    }
}
