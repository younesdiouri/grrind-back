<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * Ce que le joueur pratique. Vocabulaire fermé et **partagé** : `Training` en marque
 * ses séances, `Progression` y accroche son plafond d'XP quotidien et la portée de
 * ses modificateurs, `Rewards` ses tables de loot, `Engagement` ses classements.
 * Quatre modules le lisent, aucun ne peut importer l'enum d'un autre — sa place est
 * donc ici et pas dans `Training`.
 *
 * La liste est volontairement courte. Chaque discipline coûte une courbe à équilibrer,
 * une table de loot et une ligne de plus dans l'écran de sélection iOS ; en ouvrir une
 * se décide par un ticket, jamais en passant. Il n'y a délibérément pas de valeur
 * « autre » : elle deviendrait le fourre-tout par lequel on contourne le plafond
 * quotidien par discipline.
 *
 * Les valeurs font partie du contrat client et du schéma : elles ne changent plus.
 * Les dizaines de types d'activité de Strava se replieront sur ces cases le jour venu —
 * la correspondance appartiendra à l'adapter, pas à cet enum.
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
