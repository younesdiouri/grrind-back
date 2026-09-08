<?php

declare(strict_types=1);

namespace App\Tests\Combat;

use App\Combat\Domain\CombatRules;

final class CombatRulesFixture
{
    /** @param array<string, int> $overrides */
    public static function rules(array $overrides = []): CombatRules
    {
        return CombatRules::fromSnapshot(array_replace([
            'base_hp' => 140,
            'hp_per_1000_vitality' => 40,
            'base_damage' => 16,
            'damage_per_1000_strength' => 6,
            'minimum_damage' => 1,
            'max_attacks' => 200,
            'fatigue_floor_permille' => 400,
            'fatigue_capacity' => 4000,
            'critical_multiplier_permille' => 1500,
            'base_cooldown_ticks' => 1000,
            'maintenance_cap_permille' => 950,
            'maintenance_half_saturation' => 5000,
            'dodge_cap_permille' => 300,
            'dodge_half_saturation' => 10000,
            'critical_chance_cap_permille' => 350,
            'critical_chance_half_saturation' => 10000,
            'mitigation_cap_permille' => 700,
            'mitigation_half_saturation' => 10000,
            'guard_cap_permille' => 400,
            'guard_half_saturation' => 10000,
            'critical_resistance_cap_permille' => 500,
            'critical_resistance_half_saturation' => 10000,
            'cooldown_reduction_cap_permille' => 300,
            'cooldown_reduction_half_saturation' => 10000,
            'combo_cap_permille' => 350,
            'combo_half_saturation' => 10000,
            'precision_cap_permille' => 500,
            'precision_half_saturation' => 10000,
        ], $overrides));
    }
}
