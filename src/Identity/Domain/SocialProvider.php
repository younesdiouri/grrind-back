<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Deux, et pas un de plus : Apple l'exige dès qu'une app iOS propose une autre
 * connexion tierce, et Google couvre le reste.
 *
 * La valeur apparaît dans l'URL et dans la colonne `provider` : contrat et schéma.
 */
enum SocialProvider: string
{
    case Google = 'google';
    case Apple = 'apple';
}
