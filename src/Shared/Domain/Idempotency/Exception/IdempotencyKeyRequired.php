<?php

declare(strict_types=1);

namespace App\Shared\Domain\Idempotency\Exception;

use App\Shared\Domain\Exception\DomainError;
use App\Shared\Domain\Idempotency\IdempotencyRecord;

/**
 * L'en-tête manque, est vide, ou dépasse la longueur admise. Un seul type d'erreur pour
 * les trois : le correctif côté client est le même — un identifiant généré une fois par
 * action et réutilisé tel quel à chaque tentative.
 */
final class IdempotencyKeyRequired extends DomainError
{
    public function __construct()
    {
        parent::__construct(
            'L\'en-tête Idempotency-Key est obligatoire sur cette écriture, non vide et d\'au plus '
            .IdempotencyRecord::KEY_MAX_LENGTH.' caractères.',
            ['maxLength' => IdempotencyRecord::KEY_MAX_LENGTH],
        );
    }

    public function type(): string
    {
        return 'idempotency-key-required';
    }
}
