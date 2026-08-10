<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Les fournisseurs d'identité acceptés. Deux, et pas un de plus : Apple l'exige
 * dès qu'une app iOS propose une autre connexion tierce, et Google couvre le reste.
 *
 * La valeur est celle qui apparaît dans l'URL (`/api/auth/social/google`) et dans
 * la colonne `provider` : elle fait partie du contrat et du schéma.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';
}
