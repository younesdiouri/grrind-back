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
        foreach (DerivedStat::cases() as $stat) {
            $help = match ($stat) {
                DerivedStat::Hp, DerivedStat::Damage => 'Socle + plancher(score × coefficient / 1000), puis bonus directs. Les coefficients se règlent dans Fighter ci-dessous.',
                default => 'Plancher(plafond × score / (score + seuil)), puis bonus directs et plafond. Les moyennes sont arrondies vers le bas.',
            };
            $builder->add($stat->value, StatFormulaType::class, ['label' => $stat->label(), 'help' => $help]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null]);
    }
}
