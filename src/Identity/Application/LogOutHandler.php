<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RefreshTokenSecret;
use App\Identity\Infrastructure\Doctrine\RefreshTokenRepository;
use App\Identity\Infrastructure\Doctrine\UserDeviceRepository;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Déconnecte l'appareil dont vient le jeton, et lui seul.
 *
 * Idempotent et silencieux : un jeton inconnu ne produit pas d'erreur. Répondre
 * autrement dirait à un inconnu si le jeton qu'il tient existe.
 *
 * **Retire aussi le jeton de push de cette famille (#136, arbitrage B).** Une famille est un
 * appareil ; s'en déconnecter doit couper ses notifications sans que le client ait à s'en
 * souvenir. Même transaction que la révocation — voir {@see RefreshTokenRepository::transactional()}.
 */
final readonly class LogOutHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
        private UserDeviceRepository $devices,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(LogOut $command): void
    {
        try {
            $token = $this->refreshTokens->ofSecret(RefreshTokenSecret::fromString($command->refreshToken));
        } catch (InvalidArgumentException) {
            return;
        }

        if (null === $token) {
            return;
        }

        $familyId = $token->familyId();
        $now = $this->clock->now();

        $this->refreshTokens->transactional(function () use ($familyId, $now): void {
            $this->refreshTokens->revokeFamily($familyId, $now);
            $this->devices->discardFamily($familyId);
        });
    }
}
