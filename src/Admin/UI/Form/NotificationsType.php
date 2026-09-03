<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array{freshness_window_minutes: int, announcement_delay_seconds: int, stale_window_minutes: int, quiet_hours_start_hour: int, quiet_hours_end_hour: int}> */
final class NotificationsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['freshness_window_minutes', 'announcement_delay_seconds', 'stale_window_minutes', 'quiet_hours_start_hour', 'quiet_hours_end_hour'] as $field) {
            $builder->add($field, IntegerType::class);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
