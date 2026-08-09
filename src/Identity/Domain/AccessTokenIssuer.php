<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Port d'émission du jeton d'accès. Le domaine sait qu'il existe un jeton court
 * porteur de l'identité du user ; il ne sait pas que c'est un JWT signé RS256.
 */
interface AccessTokenIssuer
{
    public function issueFor(User $user): string;

    /**
     * Durée de vie du jeton d'accès, en secondes — le client s'en sert pour
     * rafraîchir avant expiration plutôt qu'après un 401.
     */
    public function lifetimeInSeconds(): int;
}
