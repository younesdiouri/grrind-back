<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * `ROLE_USER` n'est pas stocké : `User::getRoles()` le rajoute toujours, convention
 * Symfony. Un compte n'a une entrée en base que s'il a *plus* que ça.
 */
enum Role: string
{
    case User = 'ROLE_USER';
    case Admin = 'ROLE_ADMIN';
}
