<?php

declare(strict_types=1);

namespace App\Combat\Infrastructure\Config;

use App\Combat\Domain\CombatRules;
use App\Combat\Domain\EnemyCatalog;
use App\Shared\Infrastructure\Config\GameBalanceSection;
use InvalidArgumentException;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Le schéma de `config/game/v1/combat.yaml` — les socles d'un combattant, et le catalogue
 * des ennemis.
 *
 * Il vit dans `Combat` et non dans `Shared` : c'est ce module qui lit la section. Le schéma
 * dit la **forme** — deux blocs, `fighter` et `enemies` — et délègue la cohérence à deux
 * objets du domaine, chacun le sien : {@see CombatRules} pour les coefficients,
 * {@see EnemyCatalog} pour le catalogue. Même geste que `CommunitySection`, qui délègue à
 * `GuildRules` et `RisalaRules` séparément plutôt que d'écrire une seule règle pour deux
 * objets qui n'ont rien en commun.
 */
final class CombatSection implements GameBalanceSection
{
    public function file(): string
    {
        return 'combat.yaml';
    }

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('combat');

        $tree->getRootNode()
            ->children()
                ->arrayNode('fighter')
                    ->isRequired()
                    ->children()
                        ->integerNode('base_hp')->isRequired()->min(1)->end()
                        ->integerNode('hp_per_1000_vitality')->isRequired()->min(0)->end()
                        ->integerNode('base_damage')->isRequired()->min(0)->end()
                        ->integerNode('damage_per_1000_strength')->isRequired()->min(0)->end()
                        ->integerNode('mitigation_permille_per_1000_endurance')->isRequired()->min(0)->end()
                        // Le plafond doit rester sous 1000 millièmes (100 %) — voir
                        // `CombatRules`, qui le refuse plutôt que de le borner ici : la
                        // borne « stricte » (`< 1000`, pas `<= 1000`) n'est pas un format,
                        // c'est une règle de terminaison.
                        ->integerNode('mitigation_cap_permille')->isRequired()->min(0)->end()
                        ->integerNode('extra_turn_permille_per_1000_dexterity')->isRequired()->min(0)->end()
                        // Même garde-fou, même raison : `CombatRules` refuse un plafond qui
                        // atteint 1000 millièmes.
                        ->integerNode('extra_turn_cap_permille')->isRequired()->min(0)->end()
                        ->integerNode('dodge_permille_per_1000_mobility')->isRequired()->min(0)->end()
                        // Même garde-fou, même raison (#218) : `CombatRules` refuse un
                        // plafond d'esquive qui atteint 1000 millièmes.
                        ->integerNode('dodge_cap_permille')->isRequired()->min(0)->end()
                        // `CombatRules` refuse une valeur sous 1 : un dégât minimum nul
                        // laisserait un tour sans aucun effet.
                        ->integerNode('minimum_damage')->isRequired()->min(0)->end()
                        ->integerNode('max_turns')->isRequired()->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('enemies')
                    ->isRequired()
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key')->isRequired()->cannotBeEmpty()->end()
                            ->integerNode('level')->isRequired()->min(1)->end()
                            ->integerNode('hp')->isRequired()->min(1)->end()
                            ->integerNode('damage')->isRequired()->min(0)->end()
                            ->integerNode('mitigation_permille')->isRequired()->min(0)->end()
                            ->integerNode('extra_turn_permille')->isRequired()->min(0)->end()
                            ->integerNode('dodge_permille')->isRequired()->min(0)->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->always(static function (array $values): array {
                    /**
                     * @var array{
                     *     fighter: array{base_hp: int, hp_per_1000_vitality: int, base_damage: int, damage_per_1000_strength: int, mitigation_permille_per_1000_endurance: int, mitigation_cap_permille: int, extra_turn_permille_per_1000_dexterity: int, extra_turn_cap_permille: int, dodge_permille_per_1000_mobility: int, dodge_cap_permille: int, minimum_damage: int, max_turns: int},
                     *     enemies: list<array{key: string, level: int, hp: int, damage: int, mitigation_permille: int, extra_turn_permille: int, dodge_permille: int}>,
                     * } $values les nœuds ci-dessus ont déjà fait ce travail
                     */
                    try {
                        new CombatRules(
                            $values['fighter']['base_hp'],
                            $values['fighter']['hp_per_1000_vitality'],
                            $values['fighter']['base_damage'],
                            $values['fighter']['damage_per_1000_strength'],
                            $values['fighter']['mitigation_permille_per_1000_endurance'],
                            $values['fighter']['mitigation_cap_permille'],
                            $values['fighter']['extra_turn_permille_per_1000_dexterity'],
                            $values['fighter']['extra_turn_cap_permille'],
                            $values['fighter']['dodge_permille_per_1000_mobility'],
                            $values['fighter']['dodge_cap_permille'],
                            $values['fighter']['minimum_damage'],
                            $values['fighter']['max_turns'],
                        );

                        new EnemyCatalog($values['enemies']);
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
