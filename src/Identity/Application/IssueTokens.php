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
 * Ouvre une session : un jeton d'accès court et une nouvelle famille de refresh
 * tokens. Une famille = un appareil.
 *
 * Le JWT est fabriqué par Lexik, sans port intermédiaire : `JWTTokenManagerInterface`
 * *est* l'abstraction, en écrire une seconde par-dessus n'achetait rien.
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

    /**
     * Session complète, jeton d'accès compris. Utilisé par l'inscription.
     */
    public function __invoke(User $user): TokenPair
    {
        return $this->alongside($user, $this->jwt->create($user));
    }

    /**
     * Même chose, mais le jeton d'accès existe déjà : au login, c'est le firewall
     * qui l'a signé. On ne le refait pas pour le jeter aussitôt.
     */
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
