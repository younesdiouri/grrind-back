<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * Ce que le joueur pratique. Vocabulaire fermé, lu par quatre modules — d'où sa place
 * dans `Shared`.
 *
 * **Ce n'est plus un choix, c'est une traduction.** Le joueur ne déclare plus sa
 * discipline : la montre a décidé, et c'est {@see ActivityTypeMap} qui traduit son
 * verdict. Les neuf premières cases couvrent les sept sports de la V1, plus `MOBILITY` et
 * `CLIMBING` — conservés parce qu'ils existent chez les deux fournisseurs et que des
 * titres du Lot 3 les citent nommément.
 *
 * **`FOOTBALL`, `COURT_SPORTS` et `RACKET_SPORTS` (#166)** existent pour `Dexterity` :
 * sans elles, aucune discipline ne la fait dépasser 30 % d'une séance, et elle reste la
 * caractéristique fantôme du jeu. Ce sont des sports **mesurés**, pas déclarés — les deux
 * fournisseurs les portent nommément — donc elles ne réintroduisent aucun déclaratif.
 *
 * Pas de valeur « autre » : elle deviendrait le fourre-tout par lequel on contourne le
 * plafond d'XP quotidien par discipline. Un type de séance qu'on ne sait pas traduire
 * n'est donc **pas importé** — mais il est nommé au joueur dans la réponse d'import
 * (#92), sinon une activité disparaît sans un mot et c'est un bug de son point de vue.
 *
 * Les valeurs déjà livrées font partie du contrat client et du schéma : **elles ne
 * changent plus.** Ça ne dit rien contre l'ajout d'une case nouvelle — seulement contre
 * le renommage ou la fusion de celles qui existent, ce qui casserait un contrat déjà
 * publié.
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
    case Football = 'FOOTBALL';
    case CourtSports = 'COURT_SPORTS';
    case RacketSports = 'RACKET_SPORTS';
}
