<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Exception\InvalidRefreshToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenSecret;
use App\Identity\Infrastructure\Doctrine\RefreshTokenRepository;
use App\Identity\Infrastructure\Doctrine\UserDeviceRepository;
use InvalidArgumentException;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class RefreshSessionHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private UserDeviceRepository $devices,
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
            // donc on coupe la famille entière — et l'appareil qu'elle portait, même
            // transaction (#136, même geste que LogOutHandler).
            $familyId = $presented->familyId();

            $this->refreshTokens->transactional(function () use ($familyId, $now): void {
                $this->refreshTokens->revokeFamily($familyId, $now);
                $this->devices->discardFamily($familyId);
            });

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
            $this->jwt->createFromPayload($user, ['fid' => $rotated->familyId()->toRfc4122()]),
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
