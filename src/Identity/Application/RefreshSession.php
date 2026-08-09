<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class RefreshSession
{
    public function __construct(public string $refreshToken)
    {
    }
}
