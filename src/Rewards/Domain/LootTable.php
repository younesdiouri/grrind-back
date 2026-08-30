<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use InvalidArgumentException;

/**
 * Une table de tirage pondérée, avec sa propre bande de pièces — le corps commun aux deux
 * origines de tirage, voir le docblock de {@see LootTables} : une séance et un adversaire
 * n'ont besoin d'aucun champ de plus une fois l'éligibilité de la séance mise de côté.
 *
 * ## Ce qu'un schéma ne peut pas dire, et que ce constructeur dit à sa place
 *
 * Une somme de poids nulle et une entrée « rien » manquante sont des règles *agrégées* —
 * elles portent sur toute la table, pas sur une entrée à la fois — donc aucun `TreeBuilder`
 * ne peut les écrire ; c'est le même partage des rôles qu'`AttributeSplit` pour la somme à
 * 100 % d'une ligne de répartition.
 */
final readonly class LootTable
{
    /**
     * @param list<LootEntry> $entries
     */
    public function __construct(
        public CoinBand $coins,
        public array $entries,
    ) {
        if ([] === $this->entries) {
            throw new InvalidArgumentException('Une table de tirage vide ne rend jamais rien à tirer.');
        }

        $totalWeight = 0;
        $hasNothingEntry = false;

        foreach ($this->entries as $entry) {
            $totalWeight += $entry->weight;

            if (null === $entry->itemKey) {
                $hasNothingEntry = true;
            }
        }

        // Une table dont tous les poids valent zéro ne tire jamais rien : un poids à zéro
        // sert à désactiver une entrée sans la retirer, mais la table entière doit garder
        // au moins une chance réelle de sortir quelque chose.
        if ($totalWeight <= 0) {
            throw new InvalidArgumentException('Une table de tirage a une somme de poids nulle.');
        }

        // Un tirage bredouille est un résultat, pas un trou : sans cette entrée explicite,
        // la table promettrait un objet à chaque tirage, ce qu'aucune n'est censée faire.
        if (!$hasNothingEntry) {
            throw new InvalidArgumentException('Une table de tirage doit porter une entrée "rien" explicite.');
        }
    }
}
