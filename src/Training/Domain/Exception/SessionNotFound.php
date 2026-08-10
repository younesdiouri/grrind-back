<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;
use Symfony\Component\Uid\Uuid;

/**
 * La séance visée n'existe pas — ou appartient à quelqu'un d'autre, ce qui revient au
 * même vu d'ici. Les deux cas rendent volontairement la **même** erreur : un 403 sur la
 * séance d'autrui confirmerait son existence, et un identifiant est justement ce qu'on
 * peut essayer en boucle.
 *
 * Le message ne reprend pas l'identifiant demandé : il finirait dans les journaux du
 * client, alors qu'il n'apprend rien à celui qui l'a envoyé.
 */
final class SessionNotFound extends NotFoundError
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
