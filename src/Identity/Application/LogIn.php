<?php

declare(strict_types=1);

namespace App\Identity\Application;

final readonly class LogIn
{
    public function __construct(
        public string $email,
        public string $plainPassword,
    ) {
    }
}
