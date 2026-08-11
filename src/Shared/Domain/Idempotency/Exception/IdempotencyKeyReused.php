<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency\Exception;

use App\Shared\Domain\Exception\RuleViolationError;

/**
 * La clé a déjà servi pour une autre requête — un bug du client. Le laisser passer
 * reviendrait soit à écrire deux fois, soit à lui rendre la réponse d'une action qu'il
 * n'a pas demandée.
 */
final class IdempotencyKeyReused extends RuleViolationError
{
    public function __construct(string $key)
    {
        parent::__construct(
            'Cette clé d\'idempotence a déjà été utilisée pour une requête différente.',
            ['idempotencyKey' => $key],
        );
    }

    public function type(): string
    {
        return 'idempotency-key-reused';
    }
}
