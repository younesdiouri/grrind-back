<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Config;

use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/attributes.yaml` — la table de répartition de l'XP entre
 * les quatre caractéristiques.
 *
 * Il vit dans `Progression`, comme `XpSection` : c'est le module qui calcule l'XP qui
 * répartit ce qu'il calcule, même si `AttributeSplit` et les valeurs d'`Attribute` qu'elle
 * consomme appartiennent à `Shared` — même geste qu'`ActivityTypesSection` dans `Training`
 * pour `ActivityTypeMap`.
 *
 * Une **liste** et non une table indexée par discipline, pour la même raison qu'à
 * `XpSection` : le chargeur ne descend pas dans les listes, donc `game.attributes.splits`
 * reste un paramètre unique qu'`AttributeSplit` consomme d'un bloc.
 *
 * La couverture des disciplines, l'absence de doublon et la somme à 100 par ligne sont
 * dites par `AttributeSplit` : une règle de cohérence entre plusieurs clés ne s'écrit pas
 * deux fois.
 */
final class AttributeSplitSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'attributes.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('attributes');

        $tree->getRootNode()
            ->children()
                ->arrayNode('splits')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('discipline')->isRequired()->cannotBeEmpty()->end()
                            ->integerNode('strength')->isRequired()->min(0)->max(100)->end()
                            ->integerNode('endurance')->isRequired()->min(0)->max(100)->end()
                            ->integerNode('mobility')->isRequired()->min(0)->max(100)->end()
                            ->integerNode('dexterity')->isRequired()->min(0)->max(100)->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{splits: list<array{discipline: string, strength: int, endurance: int, mobility: int, dexterity: int}>} $values */
                    try {
                        new AttributeSplit($values['splits']);
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
