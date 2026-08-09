<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Aucune contrainte de format au-delà du non-vide : appliquer ici les règles de
 * l'inscription rejetterait en 422 des comptes créés sous d'anciennes règles, et
 * dirait au passage à quoi ressemble un mot de passe valide.
 */
final readonly class LoginRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        public string $email = '',
        #[Assert\NotBlank]
        public string $password = '',
    ) {
    }
}
