<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array{active_weeks: int, reveal_day: int, reveal_hour: int, week_timezone: string, recipient_bonus_percent: int, sender_bonus_percent: int}> */
final class RisalaSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('active_weeks', IntegerType::class)
            ->add('reveal_day', IntegerType::class)
            ->add('reveal_hour', IntegerType::class)
            ->add('week_timezone', TextType::class)
            ->add('recipient_bonus_percent', IntegerType::class)
            ->add('sender_bonus_percent', IntegerType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
