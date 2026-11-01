<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array<string, mixed>> */
final class LeagueSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('movement_percent', IntegerType::class, ['label' => 'Places de promotion/relégation (%)'])
            ->add('minimum_players_for_relegation', IntegerType::class, ['label' => 'Effectif minimum pour relégation au classement'])
            ->add('loot_luck_per_tier_percent', IntegerType::class, ['label' => 'Chance de loot par palier (points de %)']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
