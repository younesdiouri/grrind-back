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
 *
 * `shop` (#229) est facultatif — absent du tout vaut « pas vendu », voir le docblock
 * d'`ItemCatalog`. `available` a un défaut (`false`) parce que la boutique doit pouvoir lire
 * une valeur même quand seul `minimum_level` a été écrit par erreur — c'est justement la
 * config qui ment qu'`ItemCatalog` refuse. `minimum_level` n'a lui aucun défaut : sa présence
 * ou son absence, pas seulement sa valeur, fait partie de ce que ce refus vérifie.
 *
 * `kind` (#230) est facultatif, sans défaut ici non plus : `ItemCatalog` le résout à
 * `EQUIPMENT` en son absence, voir son docblock. `slot` **devient facultatif** pour la même
 * raison — un coffre n'en pose pas — et sa présence ou son absence, croisée avec `kind`, est
 * elle aussi une règle qui croise deux champs, donc vérifiée là-bas plutôt qu'ici.
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
                            // Facultatif depuis le #230 : un coffre n'en pose pas.
                            // `ItemCatalog` vérifie sa présence contre `kind`.
                            ->scalarNode('slot')->cannotBeEmpty()->end()
                            // Facultatif — absent vaut `EQUIPMENT`, voir le docblock
                            // d'`ItemCatalog`.
                            ->scalarNode('kind')->cannotBeEmpty()->end()
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
                            // Facultatif — voir le docblock de la classe pour ce que
                            // l'absence de chaque champ veut dire.
                            ->arrayNode('shop')
                                ->children()
                                    ->booleanNode('available')->defaultFalse()->end()
                                    ->integerNode('minimum_level')->min(1)->end()
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
                     *     items: list<array{key: string, rarity: string, slot?: string, kind?: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>, shop?: array{available?: bool, minimum_level?: int}}>,
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
