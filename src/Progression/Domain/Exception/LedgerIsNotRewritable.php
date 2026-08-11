<?php

declare(strict_types=1);

namespace App\Progression\Domain\Exception;

use LogicException;

/**
 * Une tentative de réécrire ou d'effacer une écriture du ledger.
 *
 * Ce n'est pas une erreur métier — aucune route ne peut la provoquer, et elle ne se
 * traduit pas en problem+json. C'est un bug, et elle sort en 500 comme tel : le jour où
 * elle est levée, c'est du code qui a pris le ledger pour une table de travail.
 */
final class LedgerIsNotRewritable extends LogicException
{
    public function __construct(string $operation, string $entity)
    {
        parent::__construct(\sprintf(
            'Le ledger d\'XP est append-only : %s sur %s est refusé. Une écriture erronée s\'annule par une transaction négative, elle ne se corrige pas.',
            $operation,
            $entity,
        ));
    }
}
