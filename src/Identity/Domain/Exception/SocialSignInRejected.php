<?php

declare(strict_types=1);

namespace App\Identity\Domain\Exception;

use App\Shared\Domain\Exception\UnauthorizedError;

/**
 * Le fournisseur a refusé le code d'autorisation : expiré, déjà échangé, émis pour
 * une autre application, ou signature invalide. Un seul type d'erreur pour tous les
 * cas — le client n'a rien de mieux à faire que de relancer le flux.
 */
final class SocialSignInRejected extends UnauthorizedError
{
    public function __construct()
    {
        parent::__construct('La connexion avec ce fournisseur a échoué. Relance la connexion.');
    }

    public function type(): string
    {
        return 'social-sign-in-rejected';
    }
}
