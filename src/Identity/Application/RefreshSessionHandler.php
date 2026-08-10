<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Exception\InvalidRefreshToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenSecret;
use App\Identity\Infrastructure\Doctrine\RefreshTokenRepository;
use InvalidArgumentException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class RefreshSessionHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private JWTTokenManagerInterface $jwt,
        private ClockInterface $clock,
        private int $accessTokenTtl,
    ) {
    }

    /**
     * @throws InvalidRefreshToken
     */
    public function __invoke(RefreshSession $command): AuthenticatedUser
    {
        $now = $this->clock->now();
        $presented = $this->find($command->refreshToken);

        if (null === $presented) {
            throw new InvalidRefreshToken();
        }

        if ($presented->isReplay()) {
            // Un jeton déjà consommé revient : soit une copie a été volée, soit le
            // vrai client rejoue après nous avoir doublés. Impossible de trancher,
            // donc on coupe la famille entière et on force un vrai login. C'est
            // brutal, et c'est le seul comportement sûr.
            $this->refreshTokens->revokeFamily($presented->familyId(), $now);
            $this->refreshTokens->commit();

            throw new InvalidRefreshToken();
        }

        if (!$presented->isUsable($now)) {
            throw new InvalidRefreshToken();
        }

        $secret = RefreshTokenSecret::generate();
        $rotated = $presented->rotate($secret, $now);

        $this->refreshTokens->add($rotated);
        // La consommation de l'ancien et l'écriture du nouveau partent ensemble :
        // un flush partiel laisserait la session sans jeton valide.
        $this->refreshTokens->commit();

        $user = $presented->user();

        return new AuthenticatedUser($user, new TokenPair(
            $this->jwt->create($user),
            $this->accessTokenTtl,
            $secret->value,
            $rotated->expiresAt(),
        ));
    }

    private function find(string $value): ?RefreshToken
    {
        try {
            return $this->refreshTokens->ofSecret(RefreshTokenSecret::fromString($value));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
