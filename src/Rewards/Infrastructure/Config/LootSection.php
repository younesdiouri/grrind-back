<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Config;

use App\Rewards\Domain\LootTables;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/loot.yaml` — les tables de tirage, par séance et par
 * adversaire.
 *
 * `version` est la version des tables, indépendante du `rulesetVersion` global — voir le
 * docblock de {@see LootTables}.
 *
 * **Ce que cette section ne peut pas valider.** Elle ne voit que ce fichier :
 * `GameBalanceLoader` valide chaque fichier indépendamment des autres, donc une clé
 * d'objet ou d'adversaire référencée ici ne peut pas être confrontée à `items.yaml` ni
 * `combat.yaml` à cet endroit — même limite que
 * {@see \App\Progression\Infrastructure\Config\AttributeSplitSection} pour `xp.yaml` et
 * `attributes.yaml`, et la même réponse : la validation ci-dessous fournit à
 * {@see LootTables} un univers de clés connues *tiré de ce fichier lui-même*, ce qui rend
 * la vérification d'existence triviale ici tout en laissant tourner pour de vrai les
 * règles qu'un seul fichier peut trancher — pas de doublon, une somme de poids positive,
 * une entrée « rien », une bande de pièces cohérente. Le vrai croisement avec les deux
 * autres fichiers est câblé par `services.yaml` et prouvé par `RewardsCoverageTest`.
 */
final class LootSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'loot.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('loot');

        $tree->getRootNode()
            ->children()
                ->integerNode('version')->isRequired()->min(1)->end()
                ->arrayNode('workout')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key')->isRequired()->cannotBeEmpty()->end()
                            ->arrayNode('eligibility')
                                ->isRequired()
                                ->children()
                                    // Absente ou vide = toute discipline — voir
                                    // `WorkoutLootTable`.
                                    ->arrayNode('disciplines')
                                        ->scalarPrototype()->end()
                                    ->end()
                                    ->integerNode('minimum_duration_minutes')->isRequired()->min(0)->end()
                                    ->integerNode('minimum_level')->isRequired()->min(1)->end()
                                ->end()
                            ->end()
                            ->append(self::coinsNode())
                            ->append(self::entriesNode())
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('adversary')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key')->isRequired()->cannotBeEmpty()->end()
                            ->append(self::coinsNode())
                            ->append(self::entriesNode())
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /**
                     * @var array{
                     *     version: int,
                     *     workout: list<array{key: string, eligibility: array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>,
                     *     adversary: list<array{key: string, coins: array{minimum: int, maximum: int}, entries: list<array{item?: string, weight: int}>}>,
                     * } $values les nœuds ci-dessus ont déjà fait ce travail
                     */
                    try {
                        // Voir le docblock de la classe : l'univers des clés connues est
                        // tiré de ce fichier lui-même, faute de pouvoir lire les deux
                        // autres depuis ici.
                        $referencedItems = array_map(
                            static fn (string $key): array => ['key' => $key],
                            self::referencedItemKeys($values['workout'], $values['adversary']),
                        );
                        $declaredAdversaries = array_map(
                            static fn (array $entry): array => ['key' => $entry['key']],
                            $values['adversary'],
                        );

                        new LootTables($values['version'], $values['workout'], $values['adversary'], $referencedItems, $declaredAdversaries, []);
                    } catch (InvalidArgumentException $incoherent) {
                        throw new InvalidConfigurationException($incoherent->getMessage(), previous: $incoherent);
                    }

                    return $values;
                })
            ->end()
        ;

        return $tree;
    }

    /** Les deux origines partagent la même forme de table — voir le docblock de `LootTables`. */
    private static function coinsNode(): ArrayNodeDefinition
    {
        $node = new TreeBuilder('coins')->getRootNode();
        \assert($node instanceof ArrayNodeDefinition);

        $node
            ->isRequired()
            ->children()
                ->integerNode('minimum')->isRequired()->min(0)->end()
                ->integerNode('maximum')->isRequired()->min(0)->end()
            ->end()
        ;

        return $node;
    }

    private static function entriesNode(): ArrayNodeDefinition
    {
        $node = new TreeBuilder('entries')->getRootNode();
        \assert($node instanceof ArrayNodeDefinition);

        $node
            ->isRequired()
            ->requiresAtLeastOneElement()
            ->arrayPrototype()
                ->children()
                    // Absent = tirage bredouille — voir `LootEntry`.
                    ->scalarNode('item')->end()
                    ->integerNode('weight')->isRequired()->min(0)->end()
                ->end()
            ->end()
        ;

        return $node;
    }

    /**
     * @param list<array{entries: list<array{item?: string}>}> $workout
     * @param list<array{entries: list<array{item?: string}>}> $adversary
     *
     * @return list<string>
     */
    private static function referencedItemKeys(array $workout, array $adversary): array
    {
        $keys = [];

        foreach ([...$workout, ...$adversary] as $table) {
            foreach ($table['entries'] as $entry) {
                if (isset($entry['item'])) {
                    $keys[$entry['item']] = true;
                }
            }
        }

        return array_keys($keys);
    }
}
