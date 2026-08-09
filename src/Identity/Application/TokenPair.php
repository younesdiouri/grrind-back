<?php

declare(strict_types=1);

namespace App\Identity\Application;

use DateTimeImmutable;

/**
 * Ce que reçoit un client après un login, une inscription ou un rafraîchissement.
 */
final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public int $expiresIn,
        public string $refreshToken,
        public DateTimeImmutable $refreshTokenExpiresAt,
    ) {
    }
}
