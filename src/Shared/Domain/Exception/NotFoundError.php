<?php

declare(strict_types=1);

namespace App\Shared\Domain\Exception;

/**
 * La ressource visée n'existe pas, ou n'est pas visible par l'appelant.
 */
abstract class NotFoundError extends DomainError
{
}
