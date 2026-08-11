<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Config;

use App\Progression\Domain\LevelCurve;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/levels.yaml`.
 *
 * Une liste, comme le barème d'XP : le chargeur ne descend pas dans les listes, donc
 * `game.levels.levels` reste un paramètre unique. La cohérence de la courbe — niveaux
 * consécutifs, seuils croissants, départ au niveau 1 — est dite par `LevelCurve`.
 */
final class LevelsSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'levels.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('levels');

        $tree->getRootNode()
            ->children()
                ->arrayNode('levels')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->integerNode('level')->isRequired()->min(1)->end()
                            ->integerNode('total_xp')->isRequired()->min(0)->end()
                            ->integerNode('skill_points')->isRequired()->min(0)->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{levels: list<array{level: int, total_xp: int, skill_points: int}>} $values */
                    try {
                        new LevelCurve($values['levels']);
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
