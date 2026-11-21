<?php

declare(strict_types=1);

namespace App\Community\Domain\Exception;

use App\Shared\Domain\Exception\NotFoundError;

final class ApplicationNotFound extends NotFoundError
{
    public function __construct()
    {
        parent::__construct('Cette candidature est introuvable.');
    }

    public function type(): string
    {
        return 'application-not-found';
    }
}
