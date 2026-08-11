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
            // Voir RefreshToken : impossible de distinguer le voleur du vrai client,
            // donc on coupe la famille entière.
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
        // Consommation de l'ancien et écriture du nouveau dans le même flush.
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
