<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;
use Symfony\Component\Uid\Uuid;

/**
 * La séance n'a pas atteint la durée plancher, et la clôture est refusée.
 *
 * Refusée, et non requalifiée en abandon : la séance reste en cours, donc rien n'est
 * perdu. Le joueur continue et clôt plus tard, ou renonce explicitement par
 * `/abandon` — qui existe précisément pour ça. Requalifier reviendrait à décider à sa
 * place, et à détruire une séance qu'un appui malheureux à 4 min 59 aurait suffi à
 * faire disparaître.
 *
 * Le temps restant part dans l'erreur : le client affiche « encore 2 min » plutôt
 * qu'un refus opaque.
 */
final class SessionTooShort extends ConflictError
{
    public function __construct(Uuid $sessionId, int $elapsedSeconds, int $minimumDurationSeconds)
    {
        parent::__construct(
            'Cette séance est trop courte pour être clôturée.',
            [
                'sessionId' => $sessionId->toRfc4122(),
                'elapsedSeconds' => $elapsedSeconds,
                'minimumDurationSeconds' => $minimumDurationSeconds,
                'remainingSeconds' => $minimumDurationSeconds - $elapsedSeconds,
            ],
        );
    }

    public function type(): string
    {
        return 'session-too-short';
    }
}
