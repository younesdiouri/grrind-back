<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * Ce que le joueur pratique. Vocabulaire fermé, lu par quatre modules — d'où sa place
 * dans `Shared`.
 *
 * Pas de valeur « autre » : elle deviendrait le fourre-tout par lequel on contourne le
 * plafond d'XP quotidien par discipline. En ouvrir une se décide par un ticket.
 *
 * Les valeurs font partie du contrat client et du schéma : elles ne changent plus.
 */
enum Discipline: string
{
    case Running = 'RUNNING';
    case Cycling = 'CYCLING';
    case Swimming = 'SWIMMING';
    case Strength = 'STRENGTH';
    case Mobility = 'MOBILITY';
    case Climbing = 'CLIMBING';
}
