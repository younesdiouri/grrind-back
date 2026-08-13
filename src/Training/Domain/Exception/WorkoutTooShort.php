<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;
use Symfony\Component\Uid\Uuid;

/**
 * Clôture refusée sous la durée plancher — refusée, et non requalifiée en abandon : la
 * séance reste en cours, donc rien n'est perdu. Requalifier déciderait à la place du
 * joueur et détruirait une séance qu'un appui malheureux à 4 min 59 ferait disparaître.
 *
 * Le temps restant part dans l'erreur : le client affiche « encore 2 min ».
 */
final class WorkoutTooShort extends ConflictError
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
