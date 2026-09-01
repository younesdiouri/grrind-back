<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use InvalidArgumentException;

/**
 * Les socles et coefficients qui transforment les caractéristiques d'un joueur en
 * combattant — PV, dégâts, mitigation, chance de tour supplémentaire, chance d'esquive. De
 * l'équilibrage, pas des constantes de classe, même geste que
 * {@see \App\Community\Domain\GuildRules} : la bonne dureté d'un combat est une question de
 * produit qui bougera après les premiers joueurs, et elle se règle dans
 * `config/game/v1/combat.yaml` sans toucher au code.
 *
 * **La dérivation elle-même n'est pas ici.** Ce ticket (#208) pose les nombres et leur
 * cohérence ; transformer une caractéristique en PV ou en dégâts est le #210. L'objet
 * reçoit donc des socles *validés*, pas encore appliqués.
 *
 * ## Ce que ce constructeur refuse, et pourquoi
 *
 * Les quatre garde-fous ci-dessous ne sont pas des bornes de format — le composant Config
 * les laisserait passer, un entier reste un entier — ce sont des règles de cohérence qui
 * rendent la **terminaison du combat démontrable** au #209 : un combat sans elles pourrait
 * boucler indéfiniment, ou du moins ne jamais se décider sur ses propres mérites. Elles
 * appartiennent au domaine, pas au schéma, exactement comme {@see GuildRules}.
 *
 * Le quatrième, sur le plafond d'esquive, est arrivé au #218, de la même famille que les
 * trois autres : un plafond qui atteindrait 1000 ‰ retire au combat sa capacité à se
 * décider sur ses propres mérites — voir le docblock de {@see BattleSimulator} pour ce que
 * ça change à sa démonstration de terminaison.
 */
final readonly class CombatRules
{
    public function __construct(
        public int $baseHp,
        public int $hpPer1000Vitality,
        public int $baseDamage,
        public int $damagePer1000Strength,
        public int $mitigationPermillePer1000Endurance,
        public int $mitigationCapPermille,
        public int $extraTurnPermillePer1000Dexterity,
        public int $extraTurnCapPermille,
        public int $dodgePermillePer1000Mobility,
        public int $dodgeCapPermille,
        public int $minimumDamage,
        public int $maxTurns,
    ) {
        // Une mitigation qui atteint 1000 millièmes (100 %) rend son porteur invulnérable :
        // si elle s'applique après le plancher de dégâts, elle le ramène à zéro, et plus
        // rien ne fait jamais baisser un point de vie.
        if ($this->mitigationCapPermille >= 1000) {
            throw new InvalidArgumentException(\sprintf('Le plafond de mitigation doit rester sous 1000 millièmes (100 %%), %d demandé.', $this->mitigationCapPermille));
        }

        // Même conséquence, par un autre chemin : un dégât plancher de zéro laisserait un
        // tour sans aucun effet, donc sans aucune garantie que l'un des deux points de vie
        // finisse par atteindre zéro.
        if ($this->minimumDamage < 1) {
            throw new InvalidArgumentException(\sprintf('Le dégât minimum doit être d\'au moins un point, %d demandé.', $this->minimumDamage));
        }

        // Une chance de tour supplémentaire qui atteint 1000 millièmes (100 %) ne rendrait
        // jamais la main : chaque tour en réenclenche un autre avec certitude, et le combat
        // ne se termine plus par lui-même — seul `maxTurns` l'arrêterait, pas la partie.
        if ($this->extraTurnCapPermille >= 1000) {
            throw new InvalidArgumentException(\sprintf('Le plafond de tour supplémentaire doit rester sous 1000 millièmes (100 %%), %d demandé.', $this->extraTurnCapPermille));
        }

        // Une cible qui esquive toujours (1000 ‰, 100 %) ne perd jamais un point de vie :
        // le combat ne se décide plus sur ses propres mérites, seul `maxTurns` l'arrête.
        if ($this->dodgeCapPermille >= 1000) {
            throw new InvalidArgumentException(\sprintf('Le plafond d\'esquive doit rester sous 1000 millièmes (100 %%), %d demandé.', $this->dodgeCapPermille));
        }
    }

    /**
     * @param array<string, int> $fighter
     */
    public static function fromSnapshot(array $fighter): self
    {
        return new self(
            $fighter['base_hp'],
            $fighter['hp_per_1000_vitality'],
            $fighter['base_damage'],
            $fighter['damage_per_1000_strength'],
            $fighter['mitigation_permille_per_1000_endurance'],
            $fighter['mitigation_cap_permille'],
            $fighter['extra_turn_permille_per_1000_dexterity'],
            $fighter['extra_turn_cap_permille'],
            $fighter['dodge_permille_per_1000_mobility'],
            $fighter['dodge_cap_permille'],
            $fighter['minimum_damage'],
            $fighter['max_turns'],
        );
    }
}
