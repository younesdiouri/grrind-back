<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\ForbiddenError;

final class AlamDifficultyForbidden extends ForbiddenError
{
    public function __construct()
    {
        parent::__construct('Seul le chef de guilde choisit la difficulté de ʿĀlam.');
    }

    public function type(): string
    {
        return 'alam-difficulty-forbidden';
    }
}
