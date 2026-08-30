<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Ce qui a provoqué une écriture au ledger de pièces. Même geste et même raison de
 * fermeture qu'{@see \App\Progression\Domain\XpReason} : aucune route ni commande ne
 * crédite pour une raison qui n'est pas listée ici, et une valeur qu'aucun code n'écrit est
 * une porte qu'on finit par pousser.
 *
 * Deux valeurs à ce ticket (#225), les deux seules sources de pièces qui existent —
 * {@see LootRoller} tire pour un workout crédité ou un combat gagné, et
 * rien d'autre n'en produit encore. `PURCHASE` (une dépense, signée négativement) et
 * `CHEST` viendront avec le Lot 6b, sans migration : `reason` est déjà une colonne assez
 * large pour les recevoir, seule cette classe changera.
 */
enum CoinReason: string
{
    case WorkoutDrop = 'WORKOUT_DROP';
    case BattleDrop = 'BATTLE_DROP';
}
