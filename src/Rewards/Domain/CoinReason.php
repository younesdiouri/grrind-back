<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Ce qui a provoqué une écriture au ledger de pièces. Même geste et même raison de
 * fermeture qu'{@see \App\Progression\Domain\XpReason} : aucune route ni commande ne
 * crédite pour une raison qui n'est pas listée ici, et une valeur qu'aucun code n'écrit est
 * une porte qu'on finit par pousser.
 *
 * Trois valeurs : {@see LootRoller} tire pour un workout crédité ou un combat gagné
 * (`WORKOUT_DROP`, `BATTLE_DROP`, #225), et la boutique dépense (`PURCHASE`, #229) — la
 * première raison qui écrit une ligne négative, voir {@see \App\Rewards\Application\CoinLedger::spend()}.
 * `CHEST` viendra avec le #230, sans migration : `reason` est déjà une colonne assez large
 * pour le recevoir, seule cette classe changera.
 */
enum CoinReason: string
{
    case WorkoutDrop = 'WORKOUT_DROP';
    case BattleDrop = 'BATTLE_DROP';
    case Purchase = 'PURCHASE';
}
