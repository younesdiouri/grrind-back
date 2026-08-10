<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency\Exception;

use App\Shared\Domain\Exception\ConflictError;

/**
 * La même requête est déjà en cours de traitement. Le cas typique : le réseau a lâché
 * pendant l'appel, le client a relancé, et les deux requêtes se croisent.
 *
 * On refuse la seconde plutôt que de la faire patienter — tenir une connexion mobile
 * ouverte en attendant l'autre coûte plus cher que de laisser le client réessayer. Le
 * 409 dit « pas encore », pas « jamais ».
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
