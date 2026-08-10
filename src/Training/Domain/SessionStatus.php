<?php

declare(strict_types=1);

namespace App\Training\Domain;

/**
 * Le cycle de vie d'une séance, et il est court : elle s'ouvre `ACTIVE`, elle se
 * ferme d'un côté ou de l'autre. Les deux états de sortie sont définitifs — rien ne
 * ramène une séance dans la course, une erreur se corrige par une transaction d'XP
 * négative et non par une réécriture d'historique.
 *
 * Contrairement à {@see \App\Shared\Domain\Activity\Discipline}, ce vocabulaire
 * n'intéresse que `Training` : les autres modules réagissent à l'événement d'une
 * séance terminée, ils n'inspectent pas son statut.
 */
enum SessionStatus: string
{
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Abandoned = 'ABANDONED';
}
