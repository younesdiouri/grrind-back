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
 * Fermé, dans `Shared` : les cinq modules de jeu le partagent, et Deptrac interdit qu'un
 * module lise l'enum d'un autre. Ajouter un type est un acte de conception, pas une
 * commodité — il faut savoir qui le produit *et* qui le consomme.
 *
 * ## Les neuf types de combat (#224) : produits par `Rewards`, consommés par `Combat` seul
 *
 * Le réflexe aurait été un port `EquippedStats` entre `Rewards` et `Combat` — exactement ce
 * que l'invariant ci-dessus interdit. Un objet équipé produit un `Modifier` comme une
 * compétence ou la guilde ; {@see \App\Combat\Application\FighterFactory} en devient le
 * cinquième consommateur, par la porte qui existe déjà.
 *
 * Deux familles, dans l'ordre où `FighterFactory` les applique — voir son docblock pour la
 * dérivation complète :
 *
 * - les **quatre caractéristiques pures** (`*_BONUS` sur Strength/Endurance/Mobility/
 *   Dexterity) s'ajoutent au total lu du snapshot *avant* la dérivation par les coefficients
 *   de `CombatRules` — même unité que le ledger, des centaines ou des milliers, jamais des
 *   unités (voir `items.yaml`) ;
 * - les **cinq stats de combat directes** (HP, dégâts, mitigation, tour supplémentaire,
 *   esquive) s'ajoutent *après* cette dérivation, avant les plafonds de `CombatRules` — les
 *   trois dernières en millièmes, comme partout dans `combat.yaml`.
 *
 * **Vitality n'a pas son type ici, et ce n'est pas un oubli** : elle est entièrement dérivée
 * des quatre autres et mesure l'équilibre d'une pratique, pas un inventaire — voir le
 * docblock de {@see \App\Shared\Domain\Activity\Vitality}. Un objet qui veut donner des
 * points de vie donne des points de vie (`HpBonus`), il ne peut pas passer par Vitality.
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

    /**
     * Ajouté au total de Strength lu du snapshot, avant dérivation en dégâts — voir le
     * docblock de la classe et celui de `FighterFactory` (#224). Produit par `Rewards`
     * (objets équipés, #29), consommé par `FighterFactory` seul.
     */
    case StrengthBonus = 'STRENGTH_BONUS';

    /** Même geste que `StrengthBonus`, avant dérivation en mitigation. */
    case EnduranceBonus = 'ENDURANCE_BONUS';

    /** Même geste que `StrengthBonus`, avant dérivation en esquive. */
    case MobilityBonus = 'MOBILITY_BONUS';

    /** Même geste que `StrengthBonus`, avant dérivation en tour supplémentaire. */
    case DexterityBonus = 'DEXTERITY_BONUS';

    /**
     * Ajouté aux points de vie du combattant, après la dérivation par `CombatRules` —
     * jamais via Vitality, voir le docblock de la classe. Consommé par `FighterFactory`
     * seul (#224).
     */
    case HpBonus = 'HP_BONUS';

    /** Ajouté aux dégâts du combattant, après la dérivation. Consommé par `FighterFactory` seul. */
    case DamageBonus = 'DAMAGE_BONUS';

    /**
     * En millièmes, ajouté à la mitigation après dérivation puis plafonné par
     * `mitigation_cap_permille` — un objet ne franchit jamais ce plafond, voir le docblock
     * de `FighterFactory`. Consommé par `FighterFactory` seul.
     */
    case MitigationBonus = 'MITIGATION_BONUS';

    /** Même geste que `MitigationBonus`, plafonné par `extra_turn_cap_permille`. */
    case ExtraTurnBonus = 'EXTRA_TURN_BONUS';

    /** Même geste que `MitigationBonus`, plafonné par `dodge_cap_permille`. */
    case DodgeBonus = 'DODGE_BONUS';
}
