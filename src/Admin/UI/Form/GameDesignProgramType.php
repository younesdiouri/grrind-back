<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Admin\Domain\GameDesignProgram;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<GameDesignProgram> */
final class GameDesignProgramType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['label' => 'Nom du programme'])
            ->add('startDate', DateType::class, ['label' => 'Date de départ', 'input' => 'string', 'widget' => 'single_text'])
            ->add('timezone', TimezoneType::class, ['label' => 'Fuseau horaire'])
            ->add('weeks', IntegerType::class, ['label' => 'Semaines (1–52)'])
            ->add('sessions', CollectionType::class, ['label' => 'Séances hebdomadaires (maximum 14)', 'entry_type' => GameDesignSessionType::class, 'allow_add' => true, 'allow_delete' => true, 'by_reference' => false, 'entry_options' => ['label' => false]])
            ->add('energy', GameDesignEnergyType::class, ['label' => 'Énergie active quotidienne', 'help' => 'Incluez les jours de repos ; zéro signifie aucune énergie renseignée. Les jours avant le départ comptent pour zéro.']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => GameDesignProgram::class]);
    }
}
