<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency;

/**
 * Où en est la requête qui a réservé la clé. Deux états seulement, parce qu'une
 * requête ratée ne laisse rien derrière elle : elle libère sa clé pour que le client
 * puisse réessayer.
 */
enum RecordStatus: string
{
    case InFlight = 'IN_FLIGHT';
    case Completed = 'COMPLETED';
}
