<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Config;

use App\Progression\Domain\DiminishingReturns;
use App\Progression\Domain\XpRates;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/xp.yaml` — le barème de base.
 *
 * Une **liste** et non une table indexée par discipline : le chargeur ne descend pas dans
 * les listes, donc `game.xp.disciplines` reste un paramètre unique que `XpRates` consomme
 * d'un bloc, au lieu de six paramètres à recâbler chaque fois qu'une discipline s'ouvre.
 *
 * La couverture des disciplines et la cohérence des taux sont dites par `XpRates` : même
 * geste qu'à `TrainingSection`, une règle du domaine ne s'écrit pas deux fois. Le schéma ne
 * garde que ce qu'il est seul à pouvoir dire — un nœud absent contre un nœud à zéro.
 */
final class XpSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'xp.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('xp');

        $tree->getRootNode()
            ->children()
                ->integerNode('base_xp_per_hour')->isRequired()->min(1)->end()
                ->arrayNode('disciplines')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('discipline')->isRequired()->cannotBeEmpty()->end()
                            ->integerNode('daily_cap_xp')->isRequired()->min(1)->end()
                            // Facultatifs, et `min(1)` : une clé absente vaut « pas de
                            // bonus », une clé à zéro voudrait dire « bonus mesuré et nul »
                            // et afficherait une ligne vide au joueur. Le schéma rend le
                            // second cas inatteignable plutôt que de laisser choisir.
                            ->integerNode('xp_per_km')->min(1)->end()
                            ->integerNode('xp_per_100m_elevation')->min(1)->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('diminishing_returns')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->integerNode('up_to_minutes')->isRequired()->min(1)->end()
                            ->integerNode('weight_percent')->isRequired()->min(0)->max(100)->end()
                        ->end()
                    ->end()
                ->end()
                ->integerNode('diminishing_returns_beyond_percent')->isRequired()->min(0)->max(100)->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{base_xp_per_hour: int, disciplines: list<array{discipline: string, daily_cap_xp: int, xp_per_km?: int, xp_per_100m_elevation?: int}>, diminishing_returns: list<array{up_to_minutes: int, weight_percent: int}>, diminishing_returns_beyond_percent: int} $values */
                    try {
                        new XpRates($values['base_xp_per_hour'], $values['disciplines']);
                        new DiminishingReturns($values['diminishing_returns'], $values['diminishing_returns_beyond_percent']);
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
