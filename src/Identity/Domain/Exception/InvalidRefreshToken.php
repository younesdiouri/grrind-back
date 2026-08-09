<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

use App\Shared\Domain\Exception\UnauthorizedError;

/**
 * Jeton inconnu, expiré, déjà consommé ou révoqué. Un seul type d'erreur pour les
 * quatre cas : le client n'a rien de mieux à faire que de renvoyer l'utilisateur
 * sur l'écran de connexion, et détailler renseignerait un attaquant.
 */
final class InvalidRefreshToken extends UnauthorizedError
{
    public function __construct()
    {
        parent::__construct('Refresh token invalide ou expiré.');
    }

    public function type(): string
    {
        return 'invalid-refresh-token';
    }
}
