<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency\Exception;

use App\Shared\Domain\Exception\ConflictError;

/**
 * La même requête est déjà en traitement : le réseau a lâché, le client a relancé, les
 * deux se croisent. On refuse la seconde plutôt que de la faire patienter — tenir une
 * connexion mobile ouverte coûte plus cher qu'un réessai. Le 409 dit « pas encore ».
 */
final class IdempotencyKeyInFlight extends ConflictError
{
    public function __construct(string $key)
    {
        parent::__construct(
            'Une requête portant cette clé d\'idempotence est déjà en cours.',
            ['idempotencyKey' => $key],
        );
    }

    public function type(): string
    {
        return 'idempotency-key-in-flight';
    }
}
