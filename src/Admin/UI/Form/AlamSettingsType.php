<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array<string, mixed>> */
final class AlamSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['strength_target', 'endurance_target', 'mobility_target', 'dexterity_target', 'vitality_target', 'start_hour', 'reset_hour', 'presentation_seconds', 'memory_limit', 'resource_base', 'resource_progression', 'activity_cap_permille', 'deficit_exponent', 'rng_cap_permille', 'rng_base_permille', 'surplus_weight_permille', 'equipment_chance_permille', 'legendary_chance_permille'] as $key) {
            $builder->add($key, IntegerType::class);
        }
        foreach (['timezone', 'resource_key', 'enemy_key'] as $key) {
            $builder->add($key, TextType::class);
        }
        $builder->add('encounter_thresholds', CollectionType::class, ['entry_type' => IntegerType::class]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
