<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Config;

use App\Shared\Domain\Activity\AttributeSplit;
use App\Shared\Domain\Activity\Vitality;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/attributes.yaml` — la table de répartition de l'XP entre
 * les quatre caractéristiques, et le plancher de Vitality (#161), leur dérivée.
 *
 * Il vit dans `Progression`, comme `XpSection` : c'est le module qui calcule l'XP qui
 * répartit ce qu'il calcule, même si `AttributeSplit`, `Vitality` et les valeurs
 * d'`Attribute` qu'elles consomment appartiennent à `Shared` — même geste
 * qu'`ActivityTypesSection` dans `Training` pour `ActivityTypeMap`.
 *
 * `splits` est une **liste** et non une table indexée par discipline, pour la même raison
 * qu'à `XpSection` : le chargeur ne descend pas dans les listes, donc `game.attributes.splits`
 * reste un paramètre unique qu'`AttributeSplit` consomme d'un bloc. `vitality`, lui, n'a
 * qu'un seul réglage : le coefficient d'équilibre n'a pas de paramètre libre, tranché en
 * revue — voir le docblock de `Vitality` — seul le plancher se patche.
 *
 * La couverture des disciplines, l'absence de doublon et la somme à 100 par ligne sont
 * dites par `AttributeSplit` ; les bornes du plancher par `Vitality` : une règle de
 * cohérence entre plusieurs clés ne s'écrit pas deux fois.
 *
 * **Ce que ce fichier seul ne peut pas valider (#167).** `AttributeSplit` n'exige une
 * ligne que des disciplines qui créditent de l'XP — une donnée qui vit dans `xp.yaml`, pas
 * ici, puisque `GameBalanceLoader` valide chaque fichier indépendamment des autres. La
 * vérification ci-dessous repasse donc `$values['splits']` comme second argument : les
 * disciplines qui **apparaissent** dans `splits` sont, par construction, celles que ce
 * seul fichier peut affirmer créditer, donc la couverture y est triviale — elle prouve
 * malgré tout la somme à 100 %, l'absence de doublon et les noms de discipline connus. Le
 * vrai croisement avec `xp.yaml` — quelles disciplines créditent réellement, et la
 * `WALKING` qui ne doit plus avoir de ligne — n'est vérifié qu'à la construction réelle du
 * service, câblée par `services.yaml` avec `%game.xp.disciplines%`, et prouvé par
 * `AttributeSplitCoverageTest`.
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
                ->arrayNode('vitality')
                    ->isRequired()
                    ->children()
                        // En millièmes : `Vitality::of()` ne laisse jamais un flottant en
                        // sortir, et ce réglage ne fait pas exception dès l'entrée.
                        ->integerNode('floor_permille')->isRequired()->min(0)->max(1000)->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{splits: list<array{discipline: string, strength: int, endurance: int, mobility: int, dexterity: int}>, vitality: array{floor_permille: int}} $values */
                    try {
                        // Voir le docblock de la classe : les disciplines de `splits`
                        // servent aussi d'univers des disciplines créditantes ici, faute
                        // de pouvoir lire `xp.yaml` depuis ce fichier-ci.
                        $disciplines = array_map(
                            static fn (array $split): array => ['discipline' => $split['discipline']],
                            $values['splits'],
                        );

                        new AttributeSplit($values['splits'], $disciplines);
                        new Vitality($values['vitality']['floor_permille']);
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
