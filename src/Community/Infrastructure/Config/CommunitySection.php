<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Config;

use App\Community\Domain\GuildRules;
use App\Community\Domain\RisalaRules;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/community.yaml` — la taille d'une guilde, et le calendrier
 * de ses Risālāt.
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
                ->arrayNode('risala')
                    ->isRequired()
                    ->children()
                        ->integerNode('active_weeks')->isRequired()->min(2)->end()
                        ->integerNode('reveal_day')->isRequired()->min(1)->max(7)->end()
                        ->integerNode('reveal_hour')->isRequired()->min(0)->max(23)->end()
                        ->scalarNode('week_timezone')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /** @var array{maximum_members: int, invite_code_lifetime_hours: int, risala: array{active_weeks: int, reveal_day: int, reveal_hour: int, week_timezone: string}} $values les `integerNode` ci-dessus ont déjà fait ce travail */
                    try {
                        new GuildRules($values['maximum_members'], $values['invite_code_lifetime_hours']);

                        // Le fuseau, lui, n'a pas de `->values()` qui le vérifie : la liste
                        // IANA n'est pas une constante de schéma, et `Timezone` la connaît
                        // déjà. Un identifiant inventé fait donc échouer la compilation du
                        // conteneur plutôt que le premier tirage.
                        new RisalaRules(
                            $values['risala']['active_weeks'],
                            $values['risala']['reveal_day'],
                            $values['risala']['reveal_hour'],
                            $values['risala']['week_timezone'],
                        );
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
