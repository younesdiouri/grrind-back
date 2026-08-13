<?php

declare(strict_types=1);

namespace App\Training\Infrastructure\Config;

use App\Shared\Domain\Activity\ActivityTypeMap;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/activity_types.yaml` — la traduction des types de séance
 * des fournisseurs santé en disciplines.
 *
 * Il vit dans `Training` parce que c'est l'import qui lit la table, même si la
 * `Discipline` qu'elle produit appartient à `Shared` : le schéma suit le lecteur, comme
 * `TrainingSection` et `XpSection`.
 *
 * Deux **listes** et non deux tables indexées par type : le chargeur ne descend pas dans
 * les listes, donc chaque source reste un paramètre unique que `ActivityTypeMap` consomme
 * d'un bloc, au lieu d'un paramètre par type d'activité — il y en a une quarantaine.
 *
 * La cohérence de la table — pas de doublon, discipline connue, chaque discipline
 * atteignable — est dite par `ActivityTypeMap` : une règle du domaine ne s'écrit pas deux
 * fois.
 */
final class ActivityTypesSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'activity_types.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('activity_types');

        $tree->getRootNode()
            ->children()
                ->append(self::mappingsOf('apple_health'))
                ->append(self::mappingsOf('health_connect'))
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{apple_health: list<array{activity_type: string, discipline: string}>, health_connect: list<array{activity_type: string, discipline: string}>} $values */
                    try {
                        new ActivityTypeMap($values['apple_health'], $values['health_connect']);
                    } catch (InvalidArgumentException $incoherent) {
                        throw new InvalidConfigurationException($incoherent->getMessage(), previous: $incoherent);
                    }

                    return $values;
                })
            ->end()
        ;

        return $tree;
    }

    /**
     * Les deux sources ont exactement la même forme ; la décrire deux fois inviterait à
     * les faire diverger sans le vouloir.
     */
    private static function mappingsOf(string $source): ArrayNodeDefinition
    {
        $node = new TreeBuilder($source)->getRootNode();

        \assert($node instanceof ArrayNodeDefinition);

        $node
            ->isRequired()
            ->requiresAtLeastOneElement()
            ->arrayPrototype()
                ->children()
                    ->scalarNode('activity_type')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('discipline')->isRequired()->cannotBeEmpty()->end()
                ->end()
            ->end()
        ;

        return $node;
    }
}
