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
 *
 * **La famille se crée avant que le jeton se signe (#136, arbitrage B).** Le jeton d'accès
 * porte le claim `fid` — la famille dont il est né — pour que `POST /api/devices` puisse
 * accrocher un appareil à sa session sans rien demander de plus au client. Ça inverse l'ordre
 * naturel au login : Lexik signe un premier JWT avant même de dispatcher l'événement de succès
 * ({@see \App\Identity\Infrastructure\Security\AuthenticationResponseListener}), et ce jeton-là
 * est jeté — la famille n'existe pas encore quand il est signé, il ne peut pas porter le bon
 * `fid`. C'est une signature de plus par login, sur un chemin qui en fait déjà une (le hachage
 * du mot de passe) : acceptée ici en toutes lettres plutôt que découverte en relecture.
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

    /** Session complète : login, inscription et social sign-in rendent tous la même forme. */
    public function __invoke(User $user): TokenPair
    {
        $secret = RefreshTokenSecret::generate();
        $refreshToken = RefreshToken::startFamily($user, $secret, $this->clock->now());

        $this->refreshTokens->add($refreshToken);
        $this->refreshTokens->commit();

        $accessToken = $this->jwt->createFromPayload($user, ['fid' => $refreshToken->familyId()->toRfc4122()]);

        return new TokenPair(
            $accessToken,
            $this->accessTokenTtl,
            $secret->value,
            $refreshToken->expiresAt(),
        );
    }
}
