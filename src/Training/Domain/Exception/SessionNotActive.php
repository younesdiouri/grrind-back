<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;
use App\Training\Domain\SessionStatus;
use Symfony\Component\Uid\Uuid;

/**
 * On a voulu clôturer ou abandonner une séance qui n'est plus en cours. C'est le cas
 * banal du client mobile qui rejoue une requête déjà passée : il apprend le statut
 * réel et se resynchronise, plutôt que de croire à un échec.
 *
 * La clé s'appelle `sessionStatus` et non `status` : un problem details RFC 9457 a déjà
 * un membre `status`, celui du code HTTP, et les membres d'extension ne l'écrasent pas
 * — le statut de la séance disparaissait donc de la réponse, précisément ce qu'elle
 * était censée apprendre au client.
 */
final class SessionNotActive extends ConflictError
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
