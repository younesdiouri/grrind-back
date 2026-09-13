<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Admin\Domain\GameDesignProfile;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GameDesignProfile> */
final class GameDesignProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['label' => 'Nom du profil']);
        foreach (['strength' => 'Force', 'endurance' => 'Endurance', 'mobility' => 'Mobilité', 'dexterity' => 'Dextérité'] as $key => $label) {
            $builder->add($key, IntegerType::class, ['label' => $label]);
        }
        $builder->add('vitalityOverride', IntegerType::class, ['label' => 'Vitalité imposée (facultatif)', 'required' => false, 'help' => 'Vide : calcul normal sans énergie récente. Ignorée par la progression sportive.'])
            ->add('equipment', ChoiceType::class, ['label' => 'Équipement fixe', 'choices' => $options['equipment_choices'], 'multiple' => true, 'required' => false, 'help' => 'Un objet par emplacement. Aucun inventaire réel n’est utilisé.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GameDesignProfile::class, 'equipment_choices' => []]);
        $resolver->setAllowedTypes('equipment_choices', 'array');
    }
}
