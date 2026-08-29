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
 * aucune vitesse, aucune initiative n'entre en jeu. `Mobility` donne l'esquive depuis le
 * #218, mais reste étrangère à l'ordre des tours : elle décide qui encaisse un coup, jamais
 * qui joue en premier. Un ordre fixe reste donc le seul qui ne demande pas d'inventer une
 * caractéristique de vitesse. C'est un choix de ce ticket, pas un énoncé du #209 ; il se
 * change sans casser la forme d'un `Fighter` le jour où une vraie initiative existera.
 *
 * ## Le jet d'esquive, avant tout calcul de dégât (#218)
 *
 * Avant que la formule ci-dessous s'applique, la **cible** tire son esquive sur sa propre
 * mobilité — jamais celle de l'attaquant, voir {@see Dodge}. Le jet passe, aucun dégât n'est
 * calculé ni appliqué : le tour émet {@see Dodge} à la place d'{@see Attack}. Le jet de tour
 * supplémentaire de l'attaquant, lui, se joue ensuite normalement, que le coup ait porté ou
 * été esquivé — l'esquive ne l'ouvre ni ne le ferme différemment d'un coup porté.
 *
 * ## La formule de dégâts
 *
 * Jouée seulement quand l'esquive n'a pas passé : `max(minimum_damage, dégât_brut −
 * dégât_brut × mitigation_de_la_cible / 1000)`. Le plancher s'applique **après** la
 * mitigation, jamais avant — c'est cet ordre-là, précisément, que {@see CombatRules}
 * protège en refusant un plafond de mitigation qui atteindrait 1000 millièmes : appliqué
 * dans l'autre sens, un plancher déjà consommé se ferait remitiger à zéro.
 *
 * ## Pourquoi la boucle termine, et ce que l'esquive y a changé
 *
 * **Avant le #218, la terminaison se démontrait par décroissance stricte : les PV
 * décroissaient à chaque attaque, sans exception, et `max_turns` n'était qu'un second
 * rideau.** Cette phrase est devenue **fausse** avec l'esquive : un tour peut désormais ne
 * retirer aucun point de vie, et rien ne borne combien de tours d'affilée peuvent esquiver.
 *
 * La terminaison reste vraie, mais elle change de nature : elle devient **presque sûre**
 * (probabilité 1) plutôt que déterministe. Le plafond d'esquive de {@see CombatRules}
 * interdit un `dodgePermille` qui atteindrait 1000 ‰ des deux côtés — il ne peut donc jamais
 * exister de combattant qui esquive avec certitude — ce qui suffit à garantir qu'un coup finit
 * par porter, mais **sans aucune borne déterministe** sur le nombre de tours que ça prend :
 * une séquence d'esquives, aussi improbable soit-elle, n'est jamais mathématiquement
 * impossible.
 *
 * **`max_turns` n'est donc plus le second rideau : c'est le seul garant dur.** Lui seul
 * borne la durée d'un combat dans le cas — improbable, jamais impossible — où l'issue n'est
 * jamais tranchée par un KO.
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

            // Le jet d'esquive se joue sur la mobilité de la CIBLE, pas de l'attaquant — voir
            // le docblock de la classe. Même geste uniforme que le tour supplémentaire plus
            // bas : `getInt(0, 999) < dodgePermille`.
            if ($rng->getInt(0, 999) < $target->dodgePermille) {
                $timeline[] = new Dodge($actor);
            } else {
                $reduction = intdiv($attacker->damage * $target->mitigationPermille, 1000);
                $damage = max($this->rules->minimumDamage, $attacker->damage - $reduction);

                if (Actor::Player === $actor) {
                    $enemyHp = max(0, $enemyHp - $damage);
                    $targetHpRemaining = $enemyHp;
                } else {
                    $playerHp = max(0, $playerHp - $damage);
                    $targetHpRemaining = $playerHp;
                }

                $timeline[] = new Attack($actor, $damage, $reduction, $targetHpRemaining);

                // La cible est morte : rien à tirer, la boucle sort d'elle-même à
                // l'évaluation suivante — mais sortir ici évite un tour supplémentaire
                // accordé à un attaquant qui n'a déjà plus personne en face. Une esquive ne
                // peut pas tuer (aucun dégât n'est appliqué), donc ce chemin ne s'atteint
                // jamais après un `Dodge`.
                if (0 === $targetHpRemaining) {
                    break;
                }
            }

            // Le tour supplémentaire est un jet uniforme sur les millièmes : proc si le
            // tirage tombe sous le seuil, ce qui rend `getInt(0, 999) < 1000` toujours vrai
            // et `< 0` toujours faux, sans cas particulier à écrire pour les deux bornes.
            // Hors du `if`/`else` ci-dessus : ce jet a lieu à l'identique que le coup ait
            // porté ou été esquivé (#218), voir le docblock de la classe.
            //
            // `$turns < maxTurns` d'abord, en court-circuit : si ce tour était le dernier
            // autorisé, la boucle va sortir juste après quoi qu'il arrive, et émettre
            // `ExtraTurn` ici laisserait un tour bonus annoncé sans l'attaque qu'il promet —
            // le client jouerait « tour bonus ! » suivi de rien.
            if ($turns < $this->rules->maxTurns && $rng->getInt(0, 999) < $attacker->extraTurnPermille) {
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
