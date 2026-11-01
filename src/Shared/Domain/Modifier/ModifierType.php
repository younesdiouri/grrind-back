<?php

declare(strict_types=1);

namespace App\Shared\Domain\Modifier;

/**
 * Vocabulaire partagé des modificateurs de compétences, objets, streak et ligue.
 * Le resolver commun reste leur unique point d'assemblage. Les taux de combat sont
 * consommés seulement depuis la source Item ; les PV/dégâts gardent leurs bonus directs.
 * Les quatre types de caractéristiques sont historiques : aucun objet actif ne les accepte (#332).
 */
enum ModifierType: string
{
    /** Un pourcentage entier appliqué au socle d'XP d'une séance. Consommé par `XpCalculator`. */
    case XpMultiplier = 'XP_MULTIPLIER';

    /**
     * Un pourcentage entier qui déplace les poids d'une table de tirage vers ses objets,
     * jamais le nombre de tirages : `20` vaut « +20 % sur le poids de chaque entrée à
     * objet », l'entrée « rien » restant inchangée — voir le docblock de
     * {@see \App\Rewards\Domain\LootRoller} (#28) pour la formule, le plancher et le
     * plafond de composition. Consommé par `LootRoller` seul.
     */
    case LootLuck = 'LOOT_LUCK';

    /** Absorbe un jour manqué sans rompre la série (#25). */
    case StreakShield = 'STREAK_SHIELD';

    /** Réservation historique, sans déblocage de séance en V0 (#334). */
    case UnlockSessionType = 'UNLOCK_SESSION_TYPE';

    /**
     * Ajouté au total de Strength lu du snapshot, avant dérivation en dégâts — voir le
     * docblock de la classe et celui de `FighterFactory` (#224). Produit par `Rewards`
     * (objets équipés, #29), consommé par `FighterFactory` seul.
     */
    case StrengthBonus = 'STRENGTH_BONUS';

    /** Même geste que `StrengthBonus`, avant dérivation en résistance à la fatigue et synergies. */
    case EnduranceBonus = 'ENDURANCE_BONUS';

    /** Même geste que `StrengthBonus`, avant dérivation en esquive. */
    case MobilityBonus = 'MOBILITY_BONUS';

    /** Même geste que `StrengthBonus`, avant dérivation en critique et synergies. */
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

    /** Même geste que `MitigationBonus`, plafonné par `combo_cap_permille`. */
    case ComboBonus = 'COMBO_BONUS';

    /** Même geste que `MitigationBonus`, plafonné par `dodge_cap_permille`. */
    case DodgeBonus = 'DODGE_BONUS';

    case MaintenanceBonus = 'MAINTENANCE_BONUS';

    case CriticalChanceBonus = 'CRITICAL_CHANCE_BONUS';

    case GuardBonus = 'GUARD_BONUS';

    case CriticalResistanceBonus = 'CRITICAL_RESISTANCE_BONUS';

    case CooldownReductionBonus = 'COOLDOWN_REDUCTION_BONUS';

    case PrecisionBonus = 'PRECISION_BONUS';

    /** Les anciens identifiants restent lisibles, mais sont retirés des objets actifs (#332). */
    public function isBaseCharacteristic(): bool
    {
        return \in_array($this, [self::StrengthBonus, self::EnduranceBonus, self::MobilityBonus, self::DexterityBonus], true);
    }
}
