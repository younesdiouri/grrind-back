<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps commun de `/api/auth/refresh` et `/api/auth/logout`.
 */
final readonly class RefreshTokenRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public string $refreshToken = '',
    ) {
    }
}
