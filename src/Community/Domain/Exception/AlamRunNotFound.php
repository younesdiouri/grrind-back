<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

final class AlamRunNotFound extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Cette édition n’existe pas.');
    }

    public function type(): string
    {
        return 'alam-run-not-found';
    }
}
