<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * L'unité d'une barre de progression vers un titre. Elle fait partie du contrat client :
 * c'est elle qui dit à SwiftUI s'il doit afficher « 12 séances », « 3 200 XP » ou formater
 * des secondes en heures.
 */
enum ProgressUnit: string
{
    case Levels = 'LEVELS';
    case Xp = 'XP';
    case Sessions = 'SESSIONS';
    case Seconds = 'SECONDS';
}
