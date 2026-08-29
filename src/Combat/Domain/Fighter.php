<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use InvalidArgumentException;

/**
 * Un combattant, tel que {@see BattleSimulator} le voit — **jamais de stats brutes**. Ni
 * Strength, ni Vitality, ni Dexterity, ni Mobility : seulement leurs effets, `hp`, `damage`,
 * `mitigationPermille`, `extraTurnPermille`, `dodgePermille`. C'est ce qui permet à un
 * joueur dérivé de ses caractéristiques et à un {@see Enemy} du catalogue — qui porte déjà
 * exactement ces cinq champs — d'entrer par la même porte : le simulateur ne sait pas, et
 * n'a pas à savoir, lequel des deux il combat.
 *
 * La dérivation caractéristique → combattant (#210, #218) construit ce type sous les
 * plafonds de {@see CombatRules} ; ce constructeur-ci ne les réapplique pas. Il ne garantit
 * que ce dont la boucle a besoin pour démarrer — un combattant vivant, des valeurs qui ne
 * sont pas négatives — et rien de plus : un test du #209 doit pouvoir forcer un
 * `extraTurnPermille` à 1000 pour prouver qu'un tour supplémentaire proc toujours, et un
 * test du #218 doit pouvoir forcer un `dodgePermille` à 1000 pour prouver qu'une cible qui
 * esquive toujours n'encaisse jamais rien — alors qu'aucune dérivation réelle ne produirait
 * jamais des valeurs pareilles.
 */
final readonly class Fighter
{
    public function __construct(
        public int $hp,
        public int $damage,
        public int $mitigationPermille,
        public int $extraTurnPermille,
        public int $dodgePermille,
    ) {
        // Un combattant à zéro point de vie n'a rien à faire à l'entrée d'un combat : la
        // boucle du simulateur suppose les deux vivants à `battle_started`.
        if ($this->hp < 1) {
            throw new InvalidArgumentException(\sprintf('Un combattant doit avoir au moins un point de vie, %d demandé.', $this->hp));
        }

        if ($this->damage < 0) {
            throw new InvalidArgumentException(\sprintf('Le dégât ne peut pas être négatif, %d demandé.', $this->damage));
        }

        if ($this->mitigationPermille < 0) {
            throw new InvalidArgumentException(\sprintf('La mitigation ne peut pas être négative, %d demandée.', $this->mitigationPermille));
        }

        if ($this->extraTurnPermille < 0) {
            throw new InvalidArgumentException(\sprintf('La chance de tour supplémentaire ne peut pas être négative, %d demandée.', $this->extraTurnPermille));
        }

        if ($this->dodgePermille < 0) {
            throw new InvalidArgumentException(\sprintf('La chance d\'esquive ne peut pas être négative, %d demandée.', $this->dodgePermille));
        }
    }
}
