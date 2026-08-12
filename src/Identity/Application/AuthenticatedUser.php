<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;

/**
 * Le compte et sa session fraîchement ouverte. Le client a besoin des deux
 * d'un coup : il affiche le profil sans second aller-retour.
 */
final readonly class AuthenticatedUser
{
    public function __construct(
        public User $user,
        public TokenPair $tokens,
    ) {
    }
}
