<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\RefreshTokenSecret;
use App\Identity\Infrastructure\Doctrine\RefreshTokenRepository;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Déconnecte l'appareil dont vient le jeton, et lui seul : les autres sessions du
 * même compte continuent.
 *
 * Idempotent et silencieux — un jeton inconnu ne produit pas d'erreur. Se
 * déconnecter deux fois, ou avec un jeton déjà expiré, doit aboutir au même état
 * qu'une déconnexion réussie, et répondre autrement dirait à un inconnu si le
 * jeton qu'il tient existe.
 */
final readonly class LogOutHandler
{
    public function __construct(
        private RefreshTokenRepository $refreshTokens,
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

        $this->refreshTokens->revokeFamily($token->familyId(), $this->clock->now());
        $this->refreshTokens->commit();
    }
}
