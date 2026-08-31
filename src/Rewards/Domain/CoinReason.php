<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Ce qui a provoqué une écriture au ledger de pièces. Même geste et même raison de
 * fermeture qu'{@see \App\Progression\Domain\XpReason} : aucune route ni commande ne
 * crédite pour une raison qui n'est pas listée ici, et une valeur qu'aucun code n'écrit est
 * une porte qu'on finit par pousser.
 *
 * Quatre valeurs : {@see LootRoller} tire pour un workout crédité, un combat gagné ou un
 * coffre ouvert (`WORKOUT_DROP`, `BATTLE_DROP`, `CHEST`, #225 et #230), et la boutique
 * dépense (`PURCHASE`, #229) — la seule raison qui écrit une ligne négative, voir
 * {@see \App\Rewards\Application\CoinLedger::spend()}. `CHEST` est arrivé sans migration au
 * #230 : `reason` était déjà une colonne assez large pour le recevoir, seule cette classe a
 * changé.
 */
enum CoinReason: string
{
    case WorkoutDrop = 'WORKOUT_DROP';
    case BattleDrop = 'BATTLE_DROP';
    case Purchase = 'PURCHASE';
    case Chest = 'CHEST';
}
