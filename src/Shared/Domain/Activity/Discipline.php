<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * Ce que le joueur pratique. Vocabulaire fermé, lu par quatre modules — d'où sa place
 * dans `Shared`.
 *
 * **Ce n'est plus un choix, c'est une traduction.** Le joueur ne déclare plus sa
 * discipline : la montre a décidé, et c'est {@see ActivityTypeMap} qui traduit son
 * verdict. Les neuf cases couvrent les sept sports de la V1, plus `MOBILITY` et
 * `CLIMBING` — conservés parce qu'ils existent chez les deux fournisseurs et que des
 * titres du Lot 3 les citent nommément.
 *
 * Pas de valeur « autre » : elle deviendrait le fourre-tout par lequel on contourne le
 * plafond d'XP quotidien par discipline. Un type de séance qu'on ne sait pas traduire
 * n'est donc **pas importé** — mais il est nommé au joueur dans la réponse d'import
 * (#92), sinon une activité disparaît sans un mot et c'est un bug de son point de vue.
 *
 * Les valeurs font partie du contrat client et du schéma : elles ne changent plus.
 */
enum Discipline: string
{
    case Running = 'RUNNING';
    case Walking = 'WALKING';
    case Cycling = 'CYCLING';
    case Swimming = 'SWIMMING';
    case Strength = 'STRENGTH';
    case Hiit = 'HIIT';
    case Hiking = 'HIKING';
    case Mobility = 'MOBILITY';
    case Climbing = 'CLIMBING';
}
