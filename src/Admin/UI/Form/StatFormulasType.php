<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Combat\Domain\DerivedStat;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<array<string, array{source: string, combination: string, secondary: ?string}>> */
final class StatFormulasType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ([DerivedStat::Hp, DerivedStat::Damage] as $stat) {
            $help = 'Socle + plancher(score × coefficient / 1000), puis bonus directs. Les coefficients se règlent dans Fighter ci-dessous.';
            $builder->add($stat->value, StatFormulaType::class, ['label' => $stat->label(), 'help' => $help]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
