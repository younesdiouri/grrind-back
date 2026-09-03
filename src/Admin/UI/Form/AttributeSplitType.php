<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/** @extends AbstractType<array{strength: int, endurance: int, mobility: int, dexterity: int}> */
final class AttributeSplitType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['strength', 'endurance', 'mobility', 'dexterity'] as $field) {
            $builder->add($field, IntegerType::class, ['constraints' => [new Range(min: 0, max: 100)]]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
