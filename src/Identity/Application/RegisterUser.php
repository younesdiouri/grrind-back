<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Locale;

/**
 * Commande d'inscription. La locale est déjà résolue par l'interface HTTP : elle ne doit pas
 * être redécidée dans un handler qui peut aussi être appelé par une autre entrée.
 */
final readonly class RegisterUser
{
    public function __construct(
        public string $email,
        public string $plainPassword,
        public string $displayName,
        public string $timezone,
        public Locale $locale,
    ) {
    }
}
