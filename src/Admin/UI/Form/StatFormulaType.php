<?php

declare(strict_types=1);

namespace App\Admin\UI\Form;

use App\Combat\Domain\StatFormula;
use InvalidArgumentException;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use ValueError;

/** @extends AbstractType<array{source: string, combination: string, secondary: ?string}> */
final class StatFormulaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $attributes = ['Force' => 'strength', 'Endurance' => 'endurance', 'Mobilité' => 'mobility', 'Dextérité' => 'dexterity', 'Vitalité' => 'vitality'];
        $builder
            ->add('source', ChoiceType::class, ['label' => 'Attribut principal', 'choices' => $attributes])
            ->add('combination', ChoiceType::class, ['label' => 'Combinaison', 'choices' => ['Attribut seul' => 'single', 'Somme' => 'sum', 'Moyenne arithmétique' => 'arithmetic_mean', 'Moyenne géométrique' => 'geometric_mean']])
            ->add('secondary', ChoiceType::class, ['label' => 'Second attribut', 'choices' => $attributes, 'required' => false, 'placeholder' => 'Aucun (attribut seul)']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => null, 'constraints' => [new Callback(static function (mixed $data, ExecutionContextInterface $context): void {
            if (!\is_array($data) || !\is_string($data['source'] ?? null) || !\is_string($data['combination'] ?? null)) {
                return;
            }
            /** @var array{source: string, combination: string, secondary: ?string} $data */
            try {
                StatFormula::fromArray($data);
            } catch (InvalidArgumentException|ValueError $exception) {
                $context->buildViolation($exception->getMessage())->addViolation();
            }
        })]]);
    }
}
