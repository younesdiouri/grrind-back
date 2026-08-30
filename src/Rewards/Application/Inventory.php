<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\InventoryItem;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * L'unique porte d'écriture de l'inventaire d'un joueur — même geste et même raison que
 * {@see CoinLedger} pour les pièces. **Personne en dehors de
 * `Rewards` n'y crédite d'objet** : le #226 (import) et le #227 (combat) traverseront cette
 * classe chacun par **son** port, défini dans son propre module — ce n'est pas à ce ticket
 * (#29) de deviner leur forme.
 *
 * Le choix de l'objet crédité n'a pas sa place ici : il vient déjà décidé de
 * {@see \App\Rewards\Domain\LootRoller}. Cette classe ne fait qu'écrire, sous la garantie que
 * porte {@see InventoryItemRepository::grant()}.
 */
final readonly class Inventory
{
    public function __construct(
        private InventoryItemRepository $items,
    ) {
    }

    /**
     * Crédite un exemplaire de `$itemKey`, obtenu par le tirage `$lootRollId`.
     *
     * `$obtainedAt` est la date **du fait qui a produit le tirage** — celle du sport pour un
     * drop de séance, l'instant du combat pour un drop de combat — jamais celle de l'appel,
     * même règle que partout ailleurs sur ce module.
     *
     * Si le joueur possède déjà `$itemKey`, `$lootRollId` et `$obtainedAt` n'écrasent rien :
     * voir le docblock d'{@see InventoryItem} pour pourquoi la provenance
     * reste celle de la toute première acquisition.
     */
    public function grant(Uuid $userId, string $itemKey, Uuid $lootRollId, DateTimeImmutable $obtainedAt): InventoryItem
    {
        return $this->items->grant($userId, $itemKey, $lootRollId, $obtainedAt);
    }
}
