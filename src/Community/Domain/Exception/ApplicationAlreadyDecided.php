<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;

final class ApplicationAlreadyDecided extends ConflictError
{
    public function __construct()
    {
        parent::__construct('Cette candidature a déjà été traitée.');
    }

    public function type(): string
    {
        return 'application-already-decided';
    }
}
