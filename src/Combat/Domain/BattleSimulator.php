<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use Random\Randomizer;

/**
 * Le moteur de combat PvE. **Fonction pure** : deux combattants et un générateur aléatoire
 * entrent, une timeline sort — aucune base, aucune horloge, aucun appel global à
 * `random_int`. C'est ce qui rend un combat rejouable à l'identique depuis sa graine, et
 * testable par table de cas plutôt que par scénario, même geste que `XpCalculator` et
 * `RisalaRotation`.
 *
 * ## Le `Randomizer` entre par la signature
 *
 * Règle n°0 : `Random\Randomizer` est fourni par PHP 8.4, et `Random\Engine` **est** une
 * interface — les tests injectent un moteur qui rend une séquence fixe sans mock maison ni
 * port à nous. La graine elle-même est l'affaire de l'appelant (#211, `Xoshiro256StarStar`
 * pour la grainer) ; ce module ne construit jamais son propre `Randomizer`.
 *
 * ## Qui ouvre le combat
 *
 * Le joueur attaque en premier, à chaque combat. Ce n'est écrit nulle part dans le ticket —
 * aucune vitesse, aucune initiative n'entre en jeu (`Mobility` est explicitement hors
 * combat en V1) — donc un ordre fixe est le seul qui ne demande pas d'inventer une
 * caractéristique de plus. C'est un choix de ce ticket, pas un énoncé du #209 ; il se
 * change sans casser la forme d'un `Fighter` le jour où une vraie initiative existera.
 *
 * ## La formule de dégâts, et pourquoi elle termine
 *
 * `max(minimum_damage, dégât_brut − dégât_brut × mitigation_de_la_cible / 1000)`. Le
 * plancher s'applique **après** la mitigation, jamais avant — c'est cet ordre-là,
 * précisément, que {@see CombatRules} protège en refusant un plafond de mitigation qui
 * atteindrait 1000 millièmes : appliqué dans l'autre sens, un plancher déjà consommé se
 * ferait remitiger à zéro. Avec les deux garde-fous respectés (mitigation strictement sous
 * 1000 ‰, dégât brut toujours positif), le résultat est toujours strictement positif : les
 * PV décroissent à chaque attaque, sans exception. `max_turns` est le second rideau, pour le
 * jour où l'équilibrage lui-même dériverait.
 *
 * ## Qui gagne à `max_turns`
 *
 * Le meilleur ratio PV restants / PV de départ — comparé par produit en croix pour ne
 * jamais introduire un flottant dans une décision de jeu. En cas d'égalité exacte, le
 * joueur l'emporte : un match nul n'a pas de mise en scène, et il fallait trancher dans un
 * sens. Ce n'est écrit nulle part dans le ticket ; c'est un choix de ce ticket.
 */
final readonly class BattleSimulator
{
    public function __construct(
        private CombatRules $rules,
    ) {
    }

    public function fight(Fighter $player, Fighter $enemy, Randomizer $rng): BattleOutcome
    {
        $playerHp = $player->hp;
        $enemyHp = $enemy->hp;

        $timeline = [new BattleStarted($playerHp, $enemyHp)];

        $actor = Actor::Player;
        $turns = 0;

        while ($playerHp > 0 && $enemyHp > 0 && $turns < $this->rules->maxTurns) {
            ++$turns;

            $attacker = Actor::Player === $actor ? $player : $enemy;
            $target = Actor::Player === $actor ? $enemy : $player;

            $reduction = intdiv($attacker->damage * $target->mitigationPermille, 1000);
            $damage = max($this->rules->minimumDamage, $attacker->damage - $reduction);

            if (Actor::Player === $actor) {
                $enemyHp = max(0, $enemyHp - $damage);
                $targetHpRemaining = $enemyHp;
            } else {
                $playerHp = max(0, $playerHp - $damage);
                $targetHpRemaining = $playerHp;
            }

            $timeline[] = new Attack($actor, $damage, $targetHpRemaining);

            // La cible est morte : rien à tirer, la boucle sort d'elle-même à l'évaluation
            // suivante — mais sortir ici évite un tour supplémentaire accordé à un
            // attaquant qui n'a déjà plus personne en face.
            if (0 === $targetHpRemaining) {
                break;
            }

            // Le tour supplémentaire est un jet uniforme sur les millièmes : proc si le
            // tirage tombe sous le seuil, ce qui rend `getInt(0, 999) < 1000` toujours vrai
            // et `< 0` toujours faux, sans cas particulier à écrire pour les deux bornes.
            if ($rng->getInt(0, 999) < $attacker->extraTurnPermille) {
                $timeline[] = new ExtraTurn($actor);

                continue;
            }

            $actor = $actor->opponent();
        }

        $result = self::result($playerHp, $enemyHp, $player->hp, $enemy->hp);

        $timeline[] = new BattleFinished($result);

        return new BattleOutcome($result, $timeline, $turns);
    }

    /**
     * Le vainqueur : celui qui reste debout si l'autre est tombé, sinon — `max_turns`
     * atteint sans KO — le meilleur ratio PV restants / PV de départ. Comparé par produit
     * en croix pour ne jamais introduire de flottant dans une décision de jeu ; à égalité
     * stricte, le joueur l'emporte, voir le docblock de la classe.
     */
    private static function result(int $playerHp, int $enemyHp, int $playerMaxHp, int $enemyMaxHp): BattleResult
    {
        if (0 === $enemyHp) {
            return BattleResult::Victory;
        }

        if (0 === $playerHp) {
            return BattleResult::Defeat;
        }

        return $playerHp * $enemyMaxHp >= $enemyHp * $playerMaxHp
            ? BattleResult::Victory
            : BattleResult::Defeat;
    }
}
