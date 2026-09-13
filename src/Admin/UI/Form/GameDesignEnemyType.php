<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array<string, int>> */
final class GameDesignEnemyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['hp' => 'PV', 'damage' => 'Dégâts', 'mitigationPermille' => 'Réduction', 'comboPermille' => 'Combo', 'dodgePermille' => 'Esquive', 'maintenancePermille' => 'Maintien', 'criticalChancePermille' => 'Critique', 'guardPermille' => 'Garde', 'criticalResistancePermille' => 'Résistance critique', 'cooldownReductionPermille' => 'Réduction du délai', 'precisionPermille' => 'Précision'] as $key => $label) {
            $direct = \in_array($key, ['hp', 'damage'], true);
            $builder->add($key, IntegerType::class, ['label' => $label.($direct ? '' : ' (‰)'), 'constraints' => [new Assert\NotNull(), new Assert\Range(min: $direct ? 1 : 0, max: $direct ? 100000000 : 999)]]);
        }
    }
}
