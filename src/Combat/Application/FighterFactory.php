<?php

declare(strict_types=1);

namespace App\Combat\Application;

use App\Combat\Domain\CombatRules;
use App\Combat\Domain\Enemy;
use App\Combat\Domain\Fighter;
use App\Shared\Application\PlayerProgression;

/**
 * La traduction caractéristique → combattant, tranchée au #210 :
 *
 * | Effet en combat              | Caractéristique | Pourquoi celle-là                                                    |
 * |-------------------------------|------------------|-----------------------------------------------------------------------|
 * | Points de vie                 | `Vitality`       | `attributes.yaml` : « ce sera les HP du personnage au PvP ».         |
 * | Dégâts infligés                | `Strength`       | Direct.                                                                |
 * | Réduction des dégâts reçus     | `Endurance`      | La capacité à encaisser, sans doublonner les HP de Vitality.         |
 * | Tour supplémentaire            | `Dexterity`      | La caractéristique du réflexe dans `attributes.yaml`.                 |
 *
 * **`Mobility` n'entre pas en V1.** Elle est réservée à l'esquive — une mécanique qui n'existe
 * pas encore — et ce n'est pas un oubli : {@see \App\Tests\Combat\Application\FighterFactoryTest}
 * prouve qu'elle ne change rien au `Fighter` produit, pour qu'elle ne se retrouve pas branchée
 * ici par distraction le jour où l'esquive arrivera.
 *
 * ## Socle + contribution, jamais un multiplicateur du socle
 *
 * Chaque effet suit `base + caractéristique × coefficient / 1000`, jamais `base × facteur` :
 * un compte neuf (les quatre caractéristiques à zéro, `Vitality::of()` rendant 0 par
 * construction — voir son docblock) reçoit donc le socle nu, pas zéro. Il se bat, et il perd
 * s'il est mauvais : le socle n'est pas une commodité de code, c'est ce qui empêche la page
 * blanche d'être une exclusion plutôt qu'une punition.
 *
 * ## Les plafonds s'appliquent ici, pas au simulateur
 *
 * `mitigation_cap_permille` et `extra_turn_cap_permille` sont appliqués à la dérivation : un
 * `Fighter` sort déjà borné. {@see \App\Combat\Domain\BattleSimulator} ne replafonne rien et n'a
 * pas à le faire — voir le docblock de {@see Fighter}, qui ne garantit lui-même que la
 * non-négativité, pas les plafonds de `CombatRules`.
 *
 * ## Aucun port : `PlayerProgression` entre déjà par la porte de `Shared`
 *
 * `App\Shared\Application\PlayerProgressions` rend, en batch et indexé par UUID, exactement ce
 * dont cette factory a besoin — le niveau (pas utilisé ici), l'`AttributeGains` des quatre
 * caractéristiques et la `vitality` bonifiée. Écrire un huitième port irait contre la règle n°0 :
 * cette classe **reçoit** une `PlayerProgression` déjà résolue, elle ne va rien chercher — pas
 * de repository, pas de base, pas de dépendance à `Progression`.
 *
 * ## Arithmétique entière, sans exception
 *
 * Comme partout sur une valeur de jeu : {@see scale()} reproduit
 * {@see \App\Shared\Domain\Activity\Vitality::scale()}, une division entière tronquée, jamais un
 * flottant. Chaque caractéristique est bornée à zéro avant d'entrer dans le calcul — même geste
 * que {@see \App\Shared\Domain\Activity\Vitality::coefficientPermille()} — parce qu'un total
 * négatif n'a de sens que le temps d'une annulation partielle du ledger, et le laisser passer
 * pousserait un socle sous zéro sans qu'aucun combat ne l'ait mérité.
 */
final readonly class FighterFactory
{
    private const int PERMILLE = 1000;

    public function __construct(
        private CombatRules $rules,
    ) {
    }

    public function forPlayer(PlayerProgression $progression): Fighter
    {
        $attributes = $progression->attributes;

        return new Fighter(
            hp: $this->rules->baseHp + self::scale(max(0, $progression->vitality), $this->rules->hpPer1000Vitality),
            damage: $this->rules->baseDamage + self::scale(max(0, $attributes->strength), $this->rules->damagePer1000Strength),
            mitigationPermille: min(
                $this->rules->mitigationCapPermille,
                self::scale(max(0, $attributes->endurance), $this->rules->mitigationPermillePer1000Endurance),
            ),
            extraTurnPermille: min(
                $this->rules->extraTurnCapPermille,
                self::scale(max(0, $attributes->dexterity), $this->rules->extraTurnPermillePer1000Dexterity),
            ),
        );
    }

    /**
     * Direct : le catalogue écrit déjà des valeurs de combattant, voir le docblock d'`Enemy`.
     * Aucune dérivation, aucun plafond à réappliquer — `CombatSection` a déjà validé le
     * catalogue à la compilation du conteneur.
     */
    public function forEnemy(Enemy $enemy): Fighter
    {
        return new Fighter($enemy->hp, $enemy->damage, $enemy->mitigationPermille, $enemy->extraTurnPermille);
    }

    private static function scale(int $total, int $permille): int
    {
        return intdiv($total * $permille, self::PERMILLE);
    }
}
