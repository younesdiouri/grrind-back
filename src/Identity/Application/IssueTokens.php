<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenSecret;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Doctrine\RefreshTokenRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Ouvre une session : un jeton d'accès court et une nouvelle famille de refresh tokens.
 * Le JWT vient de Lexik sans port intermédiaire — `JWTTokenManagerInterface` *est*
 * l'abstraction.
 */
final readonly class IssueTokens
{
    public function __construct(
        private JWTTokenManagerInterface $jwt,
        private RefreshTokenRepository $refreshTokens,
        private ClockInterface $clock,
        private int $accessTokenTtl,
    ) {
    }

    /** Session complète, jeton d'accès compris. Utilisé par l'inscription. */
    public function __invoke(User $user): TokenPair
    {
        return $this->alongside($user, $this->jwt->create($user));
    }

    /** Au login, le firewall a déjà signé le jeton : on ne le refait pas. */
    public function alongside(User $user, string $accessToken): TokenPair
    {
        $secret = RefreshTokenSecret::generate();
        $refreshToken = RefreshToken::startFamily($user, $secret, $this->clock->now());

        $this->refreshTokens->add($refreshToken);
        $this->refreshTokens->commit();

        return new TokenPair(
            $accessToken,
            $this->accessTokenTtl,
            $secret->value,
            $refreshToken->expiresAt(),
        );
    }
}
