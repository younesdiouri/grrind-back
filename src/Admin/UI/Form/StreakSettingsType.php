<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Le réglage publié du streak (#286) : la durée qui fait un jour sportif, et le coffre de
 * chaque palier. Les seuils de régularité (6 sur 7, quatre paliers) sont la spec, pas un
 * réglage.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class StreakSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('minimum_daily_seconds', IntegerType::class);
        $chests = $builder->create('chests', FormType::class, ['data_class' => null, 'label' => 'Coffre par palier (clé d’objet)']);
        foreach (['COMMON', 'RARE', 'EPIC', 'LEGENDARY'] as $rarity) {
            $chests->add($rarity, TextType::class);
        }
        $builder->add($chests);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
