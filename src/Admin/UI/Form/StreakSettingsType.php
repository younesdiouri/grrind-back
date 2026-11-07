<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Le réglage publié du streak (#346) : le coffre de chaque palier. Les seuils (7 jours
 * pointés, 3 et 6 jours de sport) sont la spec, pas un réglage.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class StreakSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $chests = $builder->create('chests', FormType::class, ['data_class' => null, 'label' => 'Coffre par palier (clé d’objet)']);
        foreach (['COMMON', 'RARE', 'LEGENDARY'] as $rarity) {
            $chests->add($rarity, TextType::class);
        }
        $builder->add($chests);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
