<?php

declare(strict_types=1);

namespace App\Shared\Domain\Modifier;

/**
 * **Un seul vocabulaire de modificateurs.** Compétences, objets équipés, streak et ligue
 * ne produisent pas chacun leur bonus maison : ils produisent tous un `Modifier` de l'un
 * de ces types, et un unique {@see \App\Shared\Application\ModifierResolver} en calcule
 * l'ensemble actif.
 *
 * C'est ce qui empêche le moteur de pourrir. Sans ce vocabulaire, chaque nouvelle source
 * de bonus ajoute une branche dans le calcul d'XP, une autre dans le tirage de loot, et
 * au bout de trois lots plus personne ne sait ce qui s'applique à qui.
 *
 * Fermé, dans `Shared` : les quatre modules de jeu le partagent, et Deptrac interdit qu'un
 * module lise l'enum d'un autre. Ajouter un type est un acte de conception, pas une
 * commodité — il faut savoir qui le produit *et* qui le consomme.
 */
enum ModifierType: string
{
    /** Un pourcentage entier appliqué au socle d'XP d'une séance. Consommé par `XpCalculator`. */
    case XpMultiplier = 'XP_MULTIPLIER';

    /** Améliore les tirages de loot (#28). */
    case LootLuck = 'LOOT_LUCK';

    /** Absorbe un jour manqué sans rompre la série (#25). */
    case StreakShield = 'STREAK_SHIELD';

    /** Ouvre un type de séance autrement indisponible (#33). */
    case UnlockSessionType = 'UNLOCK_SESSION_TYPE';
}
