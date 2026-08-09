<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * L'appelant n'a pas prouvé qui il est : identifiants faux, jeton expiré, jeton
 * rejoué. Le message reste volontairement vague — dire *ce qui* était faux revient
 * à confirmer l'existence d'un compte.
 */
abstract class UnauthorizedError extends DomainError
{
}
