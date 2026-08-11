<?php

declare(strict_types=1);

namespace App\Training\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Trop tôt après la précédente séance — et seulement après une séance qui **compte**.
 *
 * `readyAt` accompagne `remainingSeconds` pour que le client affiche un décompte sans
 * dépendre de l'heure de l'appareil.
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
