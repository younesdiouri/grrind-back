<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Admin\Domain\GameDesignProgram;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;

/** @extends AbstractType<array<string, int|string|null>> */
final class GameDesignSessionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('day', ChoiceType::class, ['label' => 'Jour', 'choices' => ['Lundi' => 1, 'Mardi' => 2, 'Mercredi' => 3, 'Jeudi' => 4, 'Vendredi' => 5, 'Samedi' => 6, 'Dimanche' => 7]])
            ->add('hour', IntegerType::class, ['label' => 'Heure (0–23)'])
            ->add('minute', IntegerType::class, ['label' => 'Minute (0–59)'])
            ->add('discipline', ChoiceType::class, ['label' => 'Sport', 'choices' => array_combine(GameDesignProgram::disciplines(), GameDesignProgram::disciplines())])
            ->add('duration', IntegerType::class, ['label' => 'Durée (secondes, maximum 86400)'])
            ->add('distance', IntegerType::class, ['label' => 'Distance (mètres)', 'required' => false])
            ->add('elevation', IntegerType::class, ['label' => 'Dénivelé positif (mètres)', 'required' => false]);
    }
}
