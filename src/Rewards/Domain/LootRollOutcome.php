<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Ce qu'un tirage produit — le pendant exact de `BattleOutcome` pour `LootRoller`.
 *
 * **`$items` est une liste, pas un objet nullable.** Une table pondérée d'une seule entrée
 * choisie ne rend aujourd'hui jamais plus d'un objet à la fois — {@see LootTable} ne
 * modélise qu'un seul tirage par table — donc cette liste ne contient aujourd'hui que zéro
 * ou un élément. Elle reste une liste pour ne pas casser la forme de ce résultat le jour où
 * une table proposera plusieurs emplacements dans le même tirage ; ce jour n'est pas venu,
 * et rien ici ne l'anticipe autrement que par ce choix de type.
 *
 * **`$itemRoll` et `$itemTotalWeight` sont le tirage brut, distincts du résultat.** Ce sont
 * eux qui vont dans la colonne `roll` de {@see LootRoll} : le nombre tiré et la somme des
 * poids qui lui donnait son sens au moment du tirage, indépendamment de toute relecture
 * future de `loot.yaml`. Les pièces n'ont pas leur pendant : leur tirage *est* déjà leur
 * résultat, un entier uniforme dans la bande — les dupliquer dans `roll` n'apporterait
 * aucune information de plus, voir le docblock de `LootRoll`.
 */
final readonly class LootRollOutcome
{
    /**
     * @param list<string> $items voir le docblock de la classe
     */
    public function __construct(
        public string $tableKey,
        public int $tableVersion,
        public int $effectiveLootLuckPercent,
        public int $itemRoll,
        public int $itemTotalWeight,
        public array $items,
        public int $coins,
    ) {
    }
}
