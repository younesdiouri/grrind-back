<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Trop tôt après la précédente séance.
 *
 * Le cooldown existe pour qu'enchaîner des séances plancher ne soit pas une stratégie.
 * Il ne se déclenche qu'après une séance qui **compte** — une séance abandonnée sous
 * le plancher n'a jamais eu lieu, et ne doit pas punir le joueur qui a lancé son
 * chronomètre par erreur.
 *
 * L'instant de disponibilité accompagne le temps restant : le client peut afficher un
 * décompte sans le recalculer de son côté, et sans dépendre de l'heure de l'appareil.
 */
final class SessionInCooldown extends ConflictError
{
    public function __construct(DateTimeImmutable $readyAt, int $remainingSeconds)
    {
        parent::__construct(
            'Une nouvelle séance ne peut pas encore être ouverte.',
            [
                'readyAt' => $readyAt->format(DateTimeInterface::ATOM),
                'remainingSeconds' => $remainingSeconds,
            ],
        );
    }

    public function type(): string
    {
        return 'session-cooldown';
    }
}
