<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AccessTokenIssuer;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenRepository;
use App\Identity\Domain\RefreshTokenSecret;
use App\Identity\Domain\User;
use Psr\Clock\ClockInterface;

/**
 * Ouvre une session : un jeton d'accès court et une nouvelle famille de refresh
 * tokens. Partagé par l'inscription et le login, qui ne diffèrent que par ce qui
 * les précède.
 */
final readonly class IssueTokens
{
    public function __construct(
        private AccessTokenIssuer $accessTokens,
        private RefreshTokenRepository $refreshTokens,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(User $user): TokenPair
    {
        $secret = RefreshTokenSecret::generate();
        $refreshToken = RefreshToken::startFamily($user, $secret, $this->clock->now());

        $this->refreshTokens->add($refreshToken);
        $this->refreshTokens->commit();

        return new TokenPair(
            $this->accessTokens->issueFor($user),
            $this->accessTokens->lifetimeInSeconds(),
            $secret->value,
            $refreshToken->expiresAt(),
        );
    }
}
