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
        foreach (['base_hp', 'hp_per_1000_vitality', 'base_damage', 'damage_per_1000_strength', 'mitigation_permille_per_1000_endurance', 'mitigation_cap_permille', 'extra_turn_permille_per_1000_dexterity', 'extra_turn_cap_permille', 'dodge_permille_per_1000_mobility', 'dodge_cap_permille', 'minimum_damage', 'max_turns'] as $field) {
            $builder->add($field, IntegerType::class);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
