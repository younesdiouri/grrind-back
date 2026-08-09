<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

use App\Shared\Domain\Exception\UnauthorizedError;

/**
 * Une seule erreur pour « adresse inconnue » et « mot de passe faux ». Les
 * distinguer transformerait le login en oracle d'existence de comptes.
 */
final class InvalidCredentials extends UnauthorizedError
{
    public function __construct()
    {
        parent::__construct('Adresse e-mail ou mot de passe incorrect.');
    }

    public function type(): string
    {
        return 'invalid-credentials';
    }
}
