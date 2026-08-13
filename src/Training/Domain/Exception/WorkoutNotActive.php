<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;
use App\Training\Domain\SessionStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Séance déjà close : le client rejoue une requête passée, apprend le statut réel et se
 * resynchronise.
 *
 * La clé s'appelle `sessionStatus` et non `status` : un problem details RFC 9457 a déjà
 * un membre `status`, celui du code HTTP, que les extensions n'écrasent pas.
 */
final class WorkoutNotActive extends ConflictError
{
    public function __construct(Uuid $sessionId, SessionStatus $status)
    {
        parent::__construct(
            'Cette séance n\'est plus en cours.',
            ['sessionId' => $sessionId->toRfc4122(), 'sessionStatus' => $status->value],
        );
    }

    public function type(): string
    {
        return 'session-not-active';
    }
}
