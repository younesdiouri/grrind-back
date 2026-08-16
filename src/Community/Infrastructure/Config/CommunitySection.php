<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Config;

use App\Community\Domain\GuildRules;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/community.yaml` — la taille d'une guilde.
 *
 * Il vit dans `Community` et non dans `Shared` : c'est ce module qui lit la section. La
 * cohérence des valeurs est déléguée à {@see GuildRules}, qui la porte déjà — deux
 * formulations de la même règle finissent toujours par diverger, et c'est celle du
 * domaine qui doit gagner.
 */
final class CommunitySection implements GameBalanceSection
{
    public function file(): string
    {
        return 'community.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('community');

        $tree->getRootNode()
            ->children()
                ->integerNode('maximum_members')->isRequired()->min(2)->end()
                ->integerNode('invite_code_lifetime_hours')->isRequired()->min(1)->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{maximum_members: int, invite_code_lifetime_hours: int} $values les `integerNode` ci-dessus ont déjà fait ce travail */
                    try {
                        new GuildRules($values['maximum_members'], $values['invite_code_lifetime_hours']);
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
