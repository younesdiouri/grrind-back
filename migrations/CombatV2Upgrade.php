<?php

declare(strict_types=1);

namespace DoctrineMigrations;

/** Conversion figée de développement (#269), partagée avec le seed d'installation.
 * Les PV/dégâts et minimum existants restent inchangés ; seules les mécaniques secondaires changent. */
final class CombatV2Upgrade
{
    /** @param array<string, mixed> $old @return array<string, int> */
    public static function fighter(array $old): array
    {
        $values = [
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
        ];
        foreach (['base_hp', 'hp_per_1000_vitality', 'base_damage', 'damage_per_1000_strength', 'minimum_damage'] as $key) {
            if (isset($old[$key])) {
                \assert(\is_int($old[$key]));
                $values[$key] = $old[$key];
            }
        }
        if (isset($old['max_turns'])) {
            \assert(\is_int($old['max_turns']));
            $values['max_attacks'] = $old['max_turns'];
        }

        return $values;
    }

    /** @param array<string, mixed> $enemy @return array<string, mixed> */
    public static function enemy(array $enemy): array
    {
        $enemy['combo_permille'] = $enemy['extra_turn_permille'];
        unset($enemy['extra_turn_permille']);
        $enemy['maintenance_permille'] = 0;
        $enemy['critical_chance_permille'] = 0;
        $enemy['guard_permille'] = 0;
        $enemy['critical_resistance_permille'] = 0;
        $enemy['cooldown_reduction_permille'] = 0;
        $enemy['precision_permille'] = 0;

        return $enemy;
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public static function snapshot(array $snapshot): array
    {
        $combat = $snapshot['combat'];
        \assert(\is_array($combat));
        /** @var array<string, mixed> $fighter */
        $fighter = $combat['fighter'];
        $combat['fighter'] = self::fighter($fighter);
        foreach (['enemies', 'bosses'] as $key) {
            /** @var list<array<string, mixed>> $enemies */
            $enemies = $combat[$key];
            $combat[$key] = array_map(self::enemy(...), $enemies);
        }
        $snapshot['combat'] = $combat;
        /** @var list<array<string, mixed>> $items */
        $items = $snapshot['items'];
        foreach ($items as &$item) {
            /** @var list<array<string, mixed>> $modifiers */
            $modifiers = $item['modifiers'] ?? [];
            foreach ($modifiers as &$modifier) {
                if ('EXTRA_TURN_BONUS' === $modifier['type']) {
                    $modifier['type'] = 'COMBO_BONUS';
                }
            }
            unset($modifier);
            if (isset($item['modifiers'])) {
                $item['modifiers'] = $modifiers;
            }
        }
        unset($item);
        $snapshot['items'] = $items;

        return $snapshot;
    }
}
