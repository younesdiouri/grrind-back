<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Config;

use App\Shared\Infrastructure\Config\GameBalanceSection;
use App\Training\Domain\WorkoutRules;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/training.yaml` — les seuils d'un workout retenu.
 *
 * Il vit dans `Training` et non dans `Shared` : c'est ce module qui lit la section, et
 * c'est ce qui lui permet de faire valider la cohérence des seuils par `WorkoutRules`
 * lui-même. Le sens des valeurs est dans le YAML, à côté d'elles.
 */
final class TrainingSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'training.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('training');

        $tree->getRootNode()
            ->children()
                ->integerNode('minimum_duration_seconds')->isRequired()->min(0)->end()
                ->integerNode('maximum_duration_seconds')->isRequired()->min(1)->end()
                ->integerNode('import_window_days')->isRequired()->min(1)->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{minimum_duration_seconds: int, maximum_duration_seconds: int, import_window_days: int} $values les `integerNode` ci-dessus ont déjà fait ce travail */

                    // La cohérence entre seuils est une règle du domaine, pas du format :
                    // on la fait dire par l'objet qui la porte plutôt que de l'écrire une
                    // seconde fois ici, où les deux formulations finiraient par diverger.
                    // Le coût est une construction jetée, une fois par compilation.
                    try {
                        new WorkoutRules(
                            $values['minimum_duration_seconds'],
                            $values['maximum_duration_seconds'],
                            $values['import_window_days'],
                        );
                    } catch (InvalidArgumentException $incoherent) {
                        throw new InvalidConfigurationException($incoherent->getMessage(), previous: $incoherent);
                    }

                    return $values;
                })
            ->end()
        ;

        return $tree;
    }
}
