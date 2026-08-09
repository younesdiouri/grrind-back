<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Commande d'inscription. Elle porte des types primitifs : la traduction en value
 * objects appartient au handler, qui est le seul à savoir ce qui est valide.
 */
final readonly class RegisterUser
{
    public function __construct(
        public string $email,
        public string $plainPassword,
        public string $displayName,
        public string $timezone,
    ) {
    }
}
