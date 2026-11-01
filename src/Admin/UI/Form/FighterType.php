<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Les bornes métier restent dans CombatRules ; ce type évite de sérialiser du JSON à la main.
 *
 * @extends AbstractType<array<string, int>>
 */
final class FighterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['base_hp', 'hp_per_1000_vitality', 'base_damage', 'damage_per_1000_strength', 'minimum_damage', 'max_attacks', 'fatigue_floor_permille', 'fatigue_capacity', 'critical_multiplier_permille', 'base_cooldown_ticks', 'maintenance_cap_permille', 'dodge_cap_permille', 'critical_chance_cap_permille', 'mitigation_cap_permille', 'guard_cap_permille', 'critical_resistance_cap_permille', 'cooldown_reduction_cap_permille', 'combo_cap_permille', 'precision_cap_permille'] as $field) {
            $help = match (true) {
                str_ends_with($field, '_cap_permille') => 'Plafond en millièmes après bonus directs. Précision et résistance critique sont des réductions relatives du taux adverse.',
                'fatigue_capacity' === $field => 'Capacité exprimée en millièmes de tentative : 4000 = 4 tentatives de référence.',
                'fatigue_floor_permille' === $field => 'Puissance minimale en millièmes, jamais un bonus au-dessus de 1000.',
                'critical_multiplier_permille' === $field => 'Multiplicateur du coup critique (1500 = ×1,5), entre 1000 et 10000.',
                'base_cooldown_ticks' === $field => 'Délai virtuel entre actions avant réduction, minimum final de 1 tick.',
                'max_attacks' === $field => 'Toutes les tentatives, Combo et esquives compris ; maximum technique 10000.',
                default => null,
            };
            $builder->add($field, IntegerType::class, ['help' => $help]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
