<?php

declare(strict_types=1);

namespace App\Shared\Domain\Activity;

/**
 * Les caractéristiques du personnage sur lesquelles l'XP d'une séance se répartit.
 * Vocabulaire fermé, à côté de {@see Discipline} qui traduit le sport pratiqué : l'un dit
 * *combien*, l'autre dit *vers où*.
 *
 * **Quatre cases, pas cinq.** Le game design en compte une cinquième, `Vitality` : elle
 * est **dérivée** des quatre autres (voir le document de game design) et ne reçoit jamais
 * d'XP directement — un cinquième cas ici lui donnerait une part de répartition qu'aucune
 * séance ne peut viser.
 *
 * **L'ordre de déclaration est normatif, pas cosmétique.** {@see AttributeSplit::distribute()}
 * départage les ex æquo de la méthode du plus fort reste dans cet ordre exact —
 * `Strength`, `Endurance`, `Mobility`, `Dexterity` — donc le réordonner changerait
 * silencieusement quelle caractéristique gagne un point de reste sur un montant ambigu, et
 * changerait le ledger déjà écrit pour un rejeu du même calcul.
 */
enum Attribute: string
{
    case Strength = 'STRENGTH';
    case Endurance = 'ENDURANCE';
    case Mobility = 'MOBILITY';
    case Dexterity = 'DEXTERITY';
}
