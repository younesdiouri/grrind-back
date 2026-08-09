<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

interface RefreshTokenRepository
{
    public function ofSecret(RefreshTokenSecret $secret): ?RefreshToken;

    public function add(RefreshToken $token): void;

    /**
     * Révoque toute la lignée d'un appareil. Appelé à la déconnexion, et sur
     * détection de rejeu.
     */
    public function revokeFamily(Uuid $familyId, DateTimeImmutable $now): void;

    /**
     * Écrit les modifications en attente. La rotation touche deux jetons — celui
     * qu'on consomme et celui qu'on émet — et doit être atomique.
     */
    public function commit(): void;
}
