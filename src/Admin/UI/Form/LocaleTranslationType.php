<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/** @extends AbstractType<array<string, mixed>> */
final class LocaleTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class)->add('hint', TextType::class, ['required' => false]);
        if ($options['introduction']) {
            $builder->add('introduction', TextareaType::class, ['required' => false, 'help' => 'Texte brut, 280 caractères maximum.', 'constraints' => [new Length(max: 280)]]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'introduction' => false]);
        $resolver->setAllowedTypes('introduction', 'bool');
    }
}
