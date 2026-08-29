<?php

declare(strict_types=1);

namespace App\Combat\Domain;

/**
 * Un tour d'attaque. `$attacker` désigne qui frappe — sa cible est l'autre valeur d'
 * {@see Actor}, jamais portée ici, un combat n'en ayant que deux.
 *
 * `$targetHpRemaining` est l'état **après** ce coup, jamais un delta : c'est la même règle
 * que les jauges du `RewardSummary` — le client ne soustrait jamais rien, il pose la barre
 * là où le combat en est.
 *
 * `$mitigated` est ce que la mitigation de la cible a retranché au dégât brut de
 * l'attaquant — la part visible d'Endurance, même geste que le breakdown d'XP qui
 * transforme un total muet en « +18 grâce à ta série » : sans ce chiffre, une jauge de
 * mitigation qui monte ne se voit nulle part dans le combat qu'elle est censée adoucir.
 *
 * **Elle ne se déduit pas de `$damage`.** C'est la réduction que la formule calcule
 * *avant* le plancher de dégât — {@see BattleSimulator} —, donc `$damage + $mitigated`
 * égale le dégât brut de l'attaquant *sauf* quand le plancher a mordu : la somme dépasse
 * alors le dégât brut, parce que `$damage` a été remonté jusqu'au plancher sans que
 * `$mitigated` en soit changé. C'est le seul endroit où les deux nombres ne
 * s'additionnent pas comme on l'attendrait, et il vaut mieux que ce soit écrit ici que
 * découvert côté client.
 */
final readonly class Attack implements BattleEvent
{
    public function __construct(
        public Actor $attacker,
        public int $damage,
        public int $mitigated,
        public int $targetHpRemaining,
    ) {
    }
}
