<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\ConflictError;

final class AlamDifficultyClosed extends ConflictError
{
    public function __construct()
    {
        parent::__construct('La difficulté de cette édition est déjà figée.');
    }

    public function type(): string
    {
        return 'alam-difficulty-closed';
    }
}
