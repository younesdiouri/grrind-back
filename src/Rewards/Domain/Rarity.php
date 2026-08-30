<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * La rareté d'un objet du catalogue. Vocabulaire fermé, à `Rewards` : elle ne pilote encore
 * aucune règle de tirage — {@see LootTable} pondère chaque entrée
 * individuellement, indépendamment de la rareté de l'objet qu'elle porte — mais elle est
 * déjà ce que l'inventaire (#29) affichera, et ce sur quoi une future table pourrait un
 * jour s'indexer plutôt que de pondérer objet par objet.
 *
 * Cinq paliers, l'échelle habituelle du genre : rien dans ce ticket ne force à en garder
 * cinq pour toujours, ouvrir un palier de plus est un ajout, pas une migration.
 */
enum Rarity: string
{
    case Common = 'COMMON';
    case Uncommon = 'UNCOMMON';
    case Rare = 'RARE';
    case Epic = 'EPIC';
    case Legendary = 'LEGENDARY';
}
