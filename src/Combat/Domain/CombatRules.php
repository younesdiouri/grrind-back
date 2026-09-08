<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use InvalidArgumentException;

/** Réglages publiés. Cap et seuil : millièmes et points d’attribut ; fatigueCapacity : millièmes de tentative.
 * Les bornes techniques limitent mémoire/arithmétique, pas la progression des dégâts ou PV. */
final readonly class CombatRules
{
    public function __construct(
        public int $baseHp,
        public int $hpPer1000Vitality,
        public int $baseDamage,
        public int $damagePer1000Strength,
        public int $minimumDamage,
        public int $maxAttacks,
        public int $fatigueFloorPermille,
        public int $fatigueCapacity,
        public int $criticalMultiplierPermille,
        public int $baseCooldownTicks,
        public int $maintenanceCapPermille,
        public int $maintenanceHalfSaturation,
        public int $dodgeCapPermille,
        public int $dodgeHalfSaturation,
        public int $criticalChanceCapPermille,
        public int $criticalChanceHalfSaturation,
        public int $mitigationCapPermille,
        public int $mitigationHalfSaturation,
        public int $guardCapPermille,
        public int $guardHalfSaturation,
        public int $criticalResistanceCapPermille,
        public int $criticalResistanceHalfSaturation,
        public int $cooldownReductionCapPermille,
        public int $cooldownReductionHalfSaturation,
        public int $comboCapPermille,
        public int $comboHalfSaturation,
        public int $precisionCapPermille,
        public int $precisionHalfSaturation,
    ) {
        foreach ([$baseHp, $minimumDamage, $maxAttacks, $fatigueCapacity, $baseCooldownTicks] as $positive) {
            if ($positive < 1 || $positive > 1_000_000) {
                throw new InvalidArgumentException('Réglage positif attendu, au plus 1000000.');
            }
        }
        if ($maxAttacks > 10000 || $criticalMultiplierPermille < 1000 || $criticalMultiplierPermille > 10000 || $fatigueFloorPermille < 1 || $fatigueFloorPermille >= 1000) {
            throw new InvalidArgumentException('Limite attaques, multiplicateur critique ou plancher fatigue invalide.');
        }
        foreach ([$baseDamage, $hpPer1000Vitality, $damagePer1000Strength] as $coefficient) {
            if ($coefficient < 0 || $coefficient > 1_000_000) {
                throw new InvalidArgumentException('Socle/coefficient hors borne technique.');
            }
        }
        if ($maintenanceCapPermille < 0 || $maintenanceCapPermille >= 1000 || $maintenanceHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil maintenance invalide.');
        }
        if ($dodgeCapPermille < 0 || $dodgeCapPermille >= 1000 || $dodgeHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil dodge invalide.');
        }
        if ($criticalChanceCapPermille < 0 || $criticalChanceCapPermille >= 1000 || $criticalChanceHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil criticalChance invalide.');
        }
        if ($mitigationCapPermille < 0 || $mitigationCapPermille >= 1000 || $mitigationHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil mitigation invalide.');
        }
        if ($guardCapPermille < 0 || $guardCapPermille >= 1000 || $guardHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil guard invalide.');
        }
        if ($criticalResistanceCapPermille < 0 || $criticalResistanceCapPermille >= 1000 || $criticalResistanceHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil criticalResistance invalide.');
        }
        if ($cooldownReductionCapPermille < 0 || $cooldownReductionCapPermille >= 1000 || $cooldownReductionHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil cooldownReduction invalide.');
        }
        if ($comboCapPermille < 0 || $comboCapPermille >= 1000 || $comboHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil combo invalide.');
        }
        if ($precisionCapPermille < 0 || $precisionCapPermille >= 1000 || $precisionHalfSaturation < 1) {
            throw new InvalidArgumentException('Cap ou seuil precision invalide.');
        }
    }

    /** @param array<string, int> $fighter */
    public static function fromSnapshot(array $fighter): self
    {
        return new self(
            baseHp: $fighter['base_hp'],
            hpPer1000Vitality: $fighter['hp_per_1000_vitality'],
            baseDamage: $fighter['base_damage'],
            damagePer1000Strength: $fighter['damage_per_1000_strength'],
            minimumDamage: $fighter['minimum_damage'],
            maxAttacks: $fighter['max_attacks'],
            fatigueFloorPermille: $fighter['fatigue_floor_permille'],
            fatigueCapacity: $fighter['fatigue_capacity'],
            criticalMultiplierPermille: $fighter['critical_multiplier_permille'],
            baseCooldownTicks: $fighter['base_cooldown_ticks'],
            maintenanceCapPermille: $fighter['maintenance_cap_permille'],
            maintenanceHalfSaturation: $fighter['maintenance_half_saturation'],
            dodgeCapPermille: $fighter['dodge_cap_permille'],
            dodgeHalfSaturation: $fighter['dodge_half_saturation'],
            criticalChanceCapPermille: $fighter['critical_chance_cap_permille'],
            criticalChanceHalfSaturation: $fighter['critical_chance_half_saturation'],
            mitigationCapPermille: $fighter['mitigation_cap_permille'],
            mitigationHalfSaturation: $fighter['mitigation_half_saturation'],
            guardCapPermille: $fighter['guard_cap_permille'],
            guardHalfSaturation: $fighter['guard_half_saturation'],
            criticalResistanceCapPermille: $fighter['critical_resistance_cap_permille'],
            criticalResistanceHalfSaturation: $fighter['critical_resistance_half_saturation'],
            cooldownReductionCapPermille: $fighter['cooldown_reduction_cap_permille'],
            cooldownReductionHalfSaturation: $fighter['cooldown_reduction_half_saturation'],
            comboCapPermille: $fighter['combo_cap_permille'],
            comboHalfSaturation: $fighter['combo_half_saturation'],
            precisionCapPermille: $fighter['precision_cap_permille'],
            precisionHalfSaturation: $fighter['precision_half_saturation'],
        );
    }
}
