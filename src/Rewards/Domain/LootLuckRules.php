<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use InvalidArgumentException;

/**
 * Le plancher et le plafond du `LOOT_LUCK` **effectif** — la somme de tous les
 * modificateurs `LOOT_LUCK` actifs d'un joueur, avant qu'{@see LootRoller} ne l'applique
 * aux poids d'une table. Ni l'un ni l'autre n'est énoncé par le ticket #28 : c'est cette
 * classe qui les porte, chargée depuis `loot_luck` dans le snapshot de jeu publié — de
 * l'équilibrage, pas des constantes de classe, même geste que {@see
 * \App\Combat\Domain\CombatRules} pour les plafonds de combat.
 *
 * ## Pourquoi le plancher est fixé à zéro ou plus, jamais négatif
 *
 * `LootRoller` ne scale que les entrées à objet, jamais l'entrée « rien » — voir son
 * docblock. Un `LOOT_LUCK` composé toujours positif ou nul garantit que le poids d'une
 * entrée à objet ne descend jamais sous son poids déclaré dans le snapshot publié, donc que le
 * poids total d'une table ne peut jamais tomber sous celui, déjà positif, qu'{@see
 * LootTable} a validé à sa construction — aucune division par une somme de poids nulle
 * n'est donc possible. Une malédiction qui réduirait la chance de loot est un objet qui
 * n'existe pas encore : elle sera un ticket à part, pas une extension silencieuse d'ici.
 *
 * ## Pourquoi un plafond, sans exception
 *
 * Même en ne scalant jamais l'entrée « rien », un `LOOT_LUCK` non borné resterait un poison
 * lent : chaque objet supplémentaire qui en porte pousserait la probabilité d'objet un peu
 * plus près de 100 % sans jamais l'atteindre tout à fait, ce qui n'empêche pas une table de
 * devenir *en pratique* un distributeur. Le plafond n'est donc pas une garantie
 * mathématique de plus — la composition additive en fournissait déjà une — c'est une
 * décision de produit : au-delà d'un certain empilement, un joueur très équipé ne doit pas
 * tirer un objet à chaque séance.
 *
 * `200` est la valeur livrée : personne n'a encore joué à ce jeu, et rien ici ne prétend
 * à un équilibrage définitif — même réserve que les tables de le snapshot publié elles-mêmes. Elle
 * se patche dans le fichier, sans migration.
 */
final readonly class LootLuckRules
{
    public function __construct(
        public int $floorPercent,
        public int $capPercent,
    ) {
        if ($this->floorPercent < 0) {
            throw new InvalidArgumentException(\sprintf('Le plancher de LOOT_LUCK ne peut pas être négatif, %d demandé — voir le docblock de la classe.', $this->floorPercent));
        }

        if ($this->capPercent < $this->floorPercent) {
            throw new InvalidArgumentException(\sprintf('Le plafond de LOOT_LUCK (%d) est sous son plancher (%d).', $this->capPercent, $this->floorPercent));
        }
    }

    /** Le `LOOT_LUCK` composé, ramené dans les bornes livrées — jamais franchies, dans un sens ni dans l'autre. */
    public function clamp(int $rawPercent): int
    {
        return max($this->floorPercent, min($this->capPercent, $rawPercent));
    }
}
