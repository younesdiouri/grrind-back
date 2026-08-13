<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;
use Symfony\Component\Uid\Uuid;

/**
 * Inexistante, ou appartenant à quelqu'un d'autre : les deux cas rendent volontairement
 * la **même** erreur. Un 403 sur la séance d'autrui confirmerait son existence, et un
 * identifiant est justement ce qu'on essaie en boucle.
 */
final class WorkoutNotFound extends NotFoundError
{
    public function __construct(Uuid $sessionId)
    {
        parent::__construct(
            'Cette séance est introuvable.',
            ['sessionId' => $sessionId->toRfc4122()],
        );
    }

    public function type(): string
    {
        return 'session-not-found';
    }
}
