<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\Item;

/**
 * Ce qu'un coffre ouvert a rendu (#230) — la même forme qu'un drop de séance ou de combat,
 * parce que c'en est un : voir le docblock de {@see \App\Rewards\Domain\LootRoller::rollForChest()}.
 *
 * `$items` porte les objets du catalogue, pas encore traduits — même choix qu'{@see
 * PurchaseReceipt} pour `$item` : cette classe ne franchit aucune frontière de module (voir
 * {@see \App\Rewards\Infrastructure\Drop\WorkoutSessionDrops} pour le cas contraire, où
 * `DroppedItem` traduit *est* le contrat), la traduction reste donc l'affaire de la
 * ressource HTTP — {@see \App\Rewards\UI\Http\Response\ChestOpenResource} — comme partout
 * ailleurs dans ce module.
 *
 * `$coins`/`$coinsBefore`/`$coinsAfter` sont lus sur le solde réel du joueur, jamais
 * reconstitués — même règle et même raison que sur `SessionDrop`/`BattleDrop` : un rejeu ou
 * une écriture concurrente pourrait faire diverger `before + coins` du vrai solde sans que
 * personne ne le remarque.
 */
final readonly class ChestOpenReceipt
{
    /**
     * @param list<Item> $items vide le plus souvent : voir le docblock de `LootRoller`
     */
    public function __construct(
        public array $items,
        public int $coins,
        public int $coinsBefore,
        public int $coinsAfter,
    ) {
    }
}
