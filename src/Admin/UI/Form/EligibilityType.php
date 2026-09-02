<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Shared\Domain\Activity\Discipline;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array<string, mixed>> */
final class EligibilityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $disciplines = array_combine(array_map(static fn (Discipline $discipline): string => $discipline->value, Discipline::cases()), array_map(static fn (Discipline $discipline): string => $discipline->value, Discipline::cases()));
        $builder
            ->add('disciplines', ChoiceType::class, ['choices' => $disciplines, 'multiple' => true])
            ->add('minimum_duration_minutes', IntegerType::class)
            ->add('minimum_level', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
