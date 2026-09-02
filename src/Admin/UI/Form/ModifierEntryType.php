<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Modifier\ModifierType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Une ligne de modificateur plutôt qu'un JSON libre : chaque valeur reste éditable et lisible. */
/** @extends AbstractType<array<string, mixed>> */
final class ModifierEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $types = array_combine(array_map(static fn (ModifierType $type): string => $type->value, ModifierType::cases()), array_map(static fn (ModifierType $type): string => $type->value, ModifierType::cases()));
        $disciplines = array_combine(array_map(static fn (Discipline $discipline): string => $discipline->value, Discipline::cases()), array_map(static fn (Discipline $discipline): string => $discipline->value, Discipline::cases()));
        $builder
            ->add('type', ChoiceType::class, ['choices' => $types])
            ->add('value', IntegerType::class)
            ->add('discipline', ChoiceType::class, ['choices' => $disciplines, 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
