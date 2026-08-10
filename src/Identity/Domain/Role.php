<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Les rôles Symfony du produit. Ce sont des chaînes côté security.yaml et côté
 * base — l'enum existe pour qu'on ne les épelle jamais à la main.
 *
 * `ROLE_USER` n'est pas stocké : `User::getRoles()` le rajoute toujours, c'est la
 * convention Symfony. Un compte n'a une entrée en base que s'il a *plus* que ça.
 */
enum Role: string
{
    case User = 'ROLE_USER';
    case Admin = 'ROLE_ADMIN';
}
