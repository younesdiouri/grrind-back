<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array{base_xp_per_hour: int, diminishing_returns: list<array{up_to_minutes: int, weight_percent: int}>, diminishing_returns_beyond_percent: int}> */
final class XpType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('base_xp_per_hour', IntegerType::class)
            ->add('diminishing_returns', CollectionType::class, [
                'entry_type' => DiminishingReturnType::class,
                'allow_add' => true,
                'allow_delete' => true,
            ])
            ->add('diminishing_returns_beyond_percent', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
