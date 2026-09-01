<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Ce qu'un objet du catalogue *est* — un équipement qu'on porte, ou un coffre qu'on ouvre
 * (#230). Fermé à deux valeurs, comme {@see LootRollOrigin} : un objet n'a jamais d'autre
 * nature que ces deux-là.
 *
 * **`EQUIPMENT` est le défaut**, voir {@see ItemCatalog} : c'est la valeur implicite de
 * le snapshot publié avant le #230, et les neuf objets déjà livrés n'ont rien eu à déclarer pour
 * le rester.
 */
enum ItemKind: string
{
    case Equipment = 'EQUIPMENT';
    case Chest = 'CHEST';
}
