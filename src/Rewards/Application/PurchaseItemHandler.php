<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\Exception\ItemNotPurchasable;
use App\Rewards\Domain\Exception\ShopLevelTooLow;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Application\PlayerProgressions;
use Psr\Clock\ClockInterface;

/**
 * Un achat, de bout en bout : résoudre l'objet, vérifier le niveau, écrire l'inventaire puis
 * débiter les pièces (#229).
 *
 * ## L'ordre des vérifications, et pourquoi
 *
 * Même structure qu'{@see EquipItemHandler} : les lectures pures d'abord, ce qui dépend d'un
 * état mutable sous verrou ensuite.
 *
 * 1. `$command->itemKey` se résout contre {@see ItemCatalog} — une clé inconnue *et* une clé
 *    connue mais hors étal (`shop.available` faux, ou absent) partagent le même refus, voir le
 *    docblock d'{@see ItemNotPurchasable} ;
 * 2. le niveau du joueur — lu via {@see PlayerProgressions}, batch par construction, un seul
 *    élément ici, même port que {@see \App\Combat\Application\FightBattleHandler} — se compare
 *    à `$item->shopMinimumLevel` ;
 * 3. aucune ligne n'est encore écrite à ce stade : un refus ici ne laisse aucune trace, même
 *    remarque que sur le choix d'un adversaire.
 *
 * ## Une seule transaction, verrous dans l'ordre du chemin de drop
 *
 * **L'inventaire se verrouille avant les pièces, jamais l'inverse.** {@see
 * \App\Rewards\Infrastructure\Drop\WorkoutSessionDrops} et {@see
 * \App\Rewards\Infrastructure\Drop\AdversaryBattleDrops} créditent déjà dans cet ordre ; un
 * achat qui les prendrait à l'envers s'interbloquerait avec un import ou un combat concurrent
 * qui tient le premier verrou en attendant le second. {@see InventoryItemRepository::transactional()}
 * ouvre la transaction unique — ni `Inventory::purchase()` ni `CoinLedger::spend()` n'ont
 * d'agrégat racine pour l'ouvrir à sa place, voir son docblock — et les deux écritures rouvrent
 * chacune leur propre `wrapInTransaction`, que DBAL referme en simple point de sauvegarde.
 *
 * `Inventory::purchase()` peut refuser (`item-already-owned`) après avoir déjà écrit sous son
 * propre verrou : la transaction entière annule cette écriture avec le reste, voir son
 * docblock. `CoinLedger::spend()`, lui, traverse la garde de {@see
 * \App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository::record()} — un achat qui ne
 * passe qu'à moitié (l'objet écrit, les pièces refusées) est exactement ce qu'une seule
 * transaction empêche.
 *
 * `sourceId` de la ligne `PURCHASE` est l'identifiant de la ligne d'inventaire que l'achat
 * vient de créer : c'est ce qui relie la dépense à ce qu'elle a acheté, sans clé étrangère et
 * sans colonne de plus — même geste qu'un `LootRoll` pour un drop.
 */
final readonly class PurchaseItemHandler
{
    public function __construct(
        private ItemCatalog $catalog,
        private PlayerProgressions $progressions,
        private InventoryItemRepository $inventoryRepository,
        private Inventory $inventory,
        private CoinLedger $coins,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ItemNotPurchasable                                    `$command->itemKey` est inconnue, ou hors étal
     * @throws ShopLevelTooLow                                       le joueur n'a pas le niveau requis
     * @throws \App\Rewards\Domain\Exception\ItemAlreadyOwned        l'objet est déjà possédé
     * @throws \App\Rewards\Domain\Exception\InsufficientCoinBalance le solde ne couvre pas le prix
     */
    public function __invoke(PurchaseItem $command): PurchaseReceipt
    {
        $item = $this->catalog->findAvailable($command->itemKey);

        if (null === $item || !$item->shopAvailable) {
            throw new ItemNotPurchasable($command->itemKey);
        }

        $progressions = $this->progressions->of([$command->userId]);
        $level = $progressions[$command->userId->toRfc4122()]->level;

        if ($level < $item->shopMinimumLevel) {
            throw new ShopLevelTooLow($item->key, $item->shopMinimumLevel, $level);
        }

        // Un achat a lieu à l'instant de la requête, comme un combat — pas d'antériorité à
        // arbitrer, contrairement à un workout. Un seul appel, réutilisé pour les deux
        // écritures ci-dessous.
        $now = $this->clock->now();

        return $this->inventoryRepository->transactional(function () use ($item, $command, $now): PurchaseReceipt {
            $line = $this->inventory->purchase($command->userId, $item, $now);

            $coinsBefore = $this->coins->balanceOf($command->userId);
            $this->coins->spend($command->userId, $line->id(), $item->priceCoins, $now);
            $coinsAfter = $this->coins->balanceOf($command->userId);

            return new PurchaseReceipt($item, $item->priceCoins, $coinsBefore, $coinsAfter);
        });
    }
}
