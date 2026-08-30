<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Config;

use App\Rewards\Domain\ItemCatalog;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/items.yaml` — le catalogue d'objets.
 *
 * Il vit dans `Rewards` et non dans `Shared` : c'est ce module qui possède le catalogue.
 * Le schéma dit la **forme** — clé, rareté, emplacement, prix, modificateurs — et délègue
 * la cohérence à {@see ItemCatalog}, même geste que `CombatSection` pour `EnemyCatalog` :
 * une clé dupliquée, une rareté ou un emplacement inconnus, un type de modificateur qui ne
 * correspond à rien de connu.
 *
 * `type` du modificateur reste un `scalarNode`, jamais une énumération figée dans ce
 * schéma : `ItemCatalog` le valide contre `ModifierType::tryFrom()`, seule façon pour le
 * #224 d'ouvrir des types de combat sans toucher à cette classe.
 */
final class ItemsSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'items.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('items');

        $tree->getRootNode()
            ->children()
                ->arrayNode('items')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('rarity')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('slot')->isRequired()->cannotBeEmpty()->end()
                            // Refusé ici, pas dans `ItemCatalog` : c'est une borne de
                            // champ, pas une règle qui croise plusieurs valeurs.
                            ->integerNode('price_coins')->isRequired()->min(0)->end()
                            ->arrayNode('modifiers')
                                ->defaultValue([])
                                ->arrayPrototype()
                                    ->children()
                                        ->scalarNode('type')->isRequired()->cannotBeEmpty()->end()
                                        ->integerNode('value')->isRequired()->end()
                                        // Absente = global, comme `Modifier::$discipline`.
                                        ->scalarNode('discipline')->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /**
                     * @var array{
                     *     items: list<array{key: string, rarity: string, slot: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>}>,
                     * } $values les nœuds ci-dessus ont déjà fait ce travail
                     */
                    try {
                        new ItemCatalog($values['items']);
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
