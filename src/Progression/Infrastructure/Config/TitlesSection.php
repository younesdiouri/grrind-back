<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Config;

use App\Progression\Domain\TitleCatalog;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/titles.yaml`.
 *
 * Une liste, comme le barème d'XP et la courbe : le chargeur ne descend pas dans les listes,
 * donc `game.titles.titles` reste un paramètre unique et l'ordre de déclaration — qui
 * départage les ex æquo — survit au conteneur compilé.
 *
 * Le schéma dit la **forme** ; la cohérence est dite par `TitleCatalog` — identifiants
 * uniques, type de condition connu, discipline obligatoire ou interdite selon le type. Même
 * geste qu'à `XpSection` : une règle du domaine ne s'écrit pas deux fois.
 *
 * Ce fichier ne porte aucun libellé. Les mots sont dans `translations/titles.{fr,en}.yaml`,
 * hors du `rulesetVersion` : corriger une faute d'orthographe n'a pas à faire croire que
 * l'équilibrage a bougé.
 */
final class TitlesSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'titles.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('titles');

        $tree->getRootNode()
            ->children()
                ->arrayNode('titles')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('id')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('condition')
                                ->isRequired()
                                ->children()
                                    ->scalarNode('type')->isRequired()->cannotBeEmpty()->end()
                                    ->integerNode('threshold')->isRequired()->min(1)->end()
                                    // Nulle par défaut plutôt qu'absente : le domaine reçoit
                                    // alors toujours la même forme, et `TitleCondition` dit
                                    // si ce null est acceptable pour ce type de condition.
                                    ->scalarNode('discipline')->defaultNull()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{titles: list<array{id: string, condition: array{type: string, threshold: int, discipline: string|null}}>} $values */
                    try {
                        new TitleCatalog($values['titles']);
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
