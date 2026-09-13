<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use App\Shared\Application\PlayerProgression;
use App\Shared\Domain\Modifier\Modifier;
use App\Shared\Domain\Modifier\ModifierSource;
use App\Shared\Domain\Modifier\ModifierType;

/** Le jeu et le laboratoire bonifient les attributs, dérivent puis appliquent les bonus directs et les bornes. */
final class FighterDerivation
{
    /**
     * @param list<Modifier>                                                                $modifiers
     * @param array<string, array{source: string, combination: string, secondary: ?string}> $formulas
     *
     * @return array{attributes: array<string, array{base: int, equipmentBonus: int, effective: int}>, fighter: Fighter}
     */
    public static function resolve(PlayerProgression $progression, array $modifiers, CombatRules $rules, array $formulas): array
    {
        $attributes = [];
        $baseAttributes = $progression->attributes->toArray();
        $equipmentModifiers = array_values(array_filter($modifiers, static fn (Modifier $modifier): bool => ModifierSource::Item === $modifier->source));
        foreach ([
            'strength' => ModifierType::StrengthBonus,
            'endurance' => ModifierType::EnduranceBonus,
            'mobility' => ModifierType::MobilityBonus,
            'dexterity' => ModifierType::DexterityBonus,
        ] as $name => $type) {
            $base = $baseAttributes[$name];
            $attributes[$name] = [
                'base' => $base,
                'equipmentBonus' => self::sumOf($equipmentModifiers, $type),
                'effective' => max(0, CombatMath::add($base, self::sumOf($modifiers, $type))),
            ];
        }
        // La vitalité inclut déjà le bonus d'énergie active ; aucun objet ne la bonifie.
        $vitality = max(0, $progression->vitality);
        $attributes['vitality'] = ['base' => $progression->vitality, 'equipmentBonus' => 0, 'effective' => $vitality];
        $scores = StatFormula::scores($formulas, array_map(static fn (array $attribute): int => $attribute['effective'], $attributes));

        $fighter = new Fighter(
            hp: max(1, CombatMath::add(CombatMath::add($rules->baseHp, CombatMath::scale($scores['hp'], $rules->hpPer1000Vitality)), self::sumOf($modifiers, ModifierType::HpBonus))),
            damage: max(0, CombatMath::add(CombatMath::add($rules->baseDamage, CombatMath::scale($scores['damage'], $rules->damagePer1000Strength)), self::sumOf($modifiers, ModifierType::DamageBonus))),
            maintenancePermille: min($rules->maintenanceCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['maintenance'], $rules->maintenanceCapPermille, $rules->maintenanceHalfSaturation), self::sumOf($modifiers, ModifierType::MaintenanceBonus)))),
            dodgePermille: min($rules->dodgeCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['dodge'], $rules->dodgeCapPermille, $rules->dodgeHalfSaturation), self::sumOf($modifiers, ModifierType::DodgeBonus)))),
            criticalChancePermille: min($rules->criticalChanceCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['critical_chance'], $rules->criticalChanceCapPermille, $rules->criticalChanceHalfSaturation), self::sumOf($modifiers, ModifierType::CriticalChanceBonus)))),
            mitigationPermille: min($rules->mitigationCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['mitigation'], $rules->mitigationCapPermille, $rules->mitigationHalfSaturation), self::sumOf($modifiers, ModifierType::MitigationBonus)))),
            guardPermille: min($rules->guardCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['guard'], $rules->guardCapPermille, $rules->guardHalfSaturation), self::sumOf($modifiers, ModifierType::GuardBonus)))),
            criticalResistancePermille: min($rules->criticalResistanceCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['critical_resistance'], $rules->criticalResistanceCapPermille, $rules->criticalResistanceHalfSaturation), self::sumOf($modifiers, ModifierType::CriticalResistanceBonus)))),
            cooldownReductionPermille: min($rules->cooldownReductionCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['cooldown_reduction'], $rules->cooldownReductionCapPermille, $rules->cooldownReductionHalfSaturation), self::sumOf($modifiers, ModifierType::CooldownReductionBonus)))),
            comboPermille: min($rules->comboCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['combo'], $rules->comboCapPermille, $rules->comboHalfSaturation), self::sumOf($modifiers, ModifierType::ComboBonus)))),
            precisionPermille: min($rules->precisionCapPermille, max(0, CombatMath::add(CombatMath::rate($scores['precision'], $rules->precisionCapPermille, $rules->precisionHalfSaturation), self::sumOf($modifiers, ModifierType::PrecisionBonus)))),
        );

        return ['attributes' => $attributes, 'fighter' => $fighter];
    }

    /** @param list<Modifier> $modifiers */
    private static function sumOf(array $modifiers, ModifierType $type): int
    {
        $total = 0;

        foreach ($modifiers as $modifier) {
            if ($modifier->type === $type) {
                $total = CombatMath::add($total, $modifier->value);
            }
        }

        return $total;
    }
}
