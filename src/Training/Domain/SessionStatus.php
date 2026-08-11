<?php

declare(strict_types=1);

namespace App\Training\Domain;

/**
 * Les deux états de sortie sont définitifs : rien ne ramène une séance dans la course.
 * Une erreur se corrige par une transaction d'XP négative, pas par une réécriture
 * d'historique.
 *
 * Reste dans `Training` : les autres modules réagissent à l'événement de clôture, ils
 * n'inspectent pas le statut.
 */
enum SessionStatus: string
{
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Abandoned = 'ABANDONED';
}
