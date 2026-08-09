<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * L'état actuel du système interdit l'opération : unicité déjà prise, transition
 * d'état impossible, session déjà en cours. Rejouer à l'identique ne changera rien.
 */
abstract class ConflictError extends DomainError
{
}
