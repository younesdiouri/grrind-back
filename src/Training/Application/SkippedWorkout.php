<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Training\Domain\ImportSkipReason;

/**
 * Un workout du lot qui n'a pas été crédité, et pourquoi.
 *
 * Il porte son `activityType` en plus de son identifiant : c'est ce qui permet au client
 * d'écrire « le curling n'est pas encore un sport chez nous » au lieu de « 1 séance
 * ignorée ». Sans lui, la raison `UNSUPPORTED_ACTIVITY` serait vraie et inutilisable.
 */
final readonly class SkippedWorkout
{
    public function __construct(
        public string $externalId,
        public string $activityType,
        public ImportSkipReason $reason,
    ) {
    }
}
