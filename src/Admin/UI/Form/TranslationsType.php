<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Deux langues obligatoires du catalogue, rendues en champs et non en document JSON. */
/** @extends AbstractType<array<string, mixed>> */
final class TranslationsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fr', LocaleTranslationType::class, ['label' => 'Français'])
            ->add('en', LocaleTranslationType::class, ['label' => 'English']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
