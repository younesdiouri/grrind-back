<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Positive;

/** @extends AbstractType<array{floor_permille: int, window_days: int, target_active_kcal: int, bonus_cap_permille: int}> */
final class VitalityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('floor_permille', IntegerType::class)
            ->add('window_days', IntegerType::class, ['constraints' => [new Positive()]])
            ->add('target_active_kcal', IntegerType::class)
            ->add('bonus_cap_permille', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
