<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/** @extends AbstractType<array{item: string, quantity: int}> */
final class RecipeCostType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('item', TextType::class, ['label' => 'Clé de la ressource', 'constraints' => [new Assert\NotBlank()]])
            ->add('quantity', IntegerType::class, ['label' => 'Quantité consommée', 'constraints' => [new Assert\Positive()]]);
    }
}
