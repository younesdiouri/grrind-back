<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\CoinReason;
use App\Rewards\Domain\Exception\ItemNotOwned;
use App\Rewards\Domain\Exception\ItemNotSellable;
use App\Rewards\Domain\Exception\SalePriceChanged;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\ItemKind;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le prix confirmé n'est qu'une précondition : seul le snapshot ouvert fixe le crédit.
 * Inventaire puis pièces, dans une transaction comme l'achat. Le verrou pièces précède
 * les deux soldes du reçu, même pour zéro (qui ne crée aucune écriture au ledger).
 * Chaque vente reçoit un UUID source distinct : plusieurs ventes de la même pile sont
 * légitimes. Le rejeu HTTP est assuré par Idempotent, sans relancer ce handler.
 */
final readonly class SellItemHandler
{
    public function __construct(private ItemCatalog $catalog, private InventoryItemRepository $inventory, private CoinLedger $coins, private ClockInterface $clock)
    {
    }

    public function __invoke(SellItem $command): SaleReceipt
    {
        $item = $this->catalog->find($command->itemKey) ?? throw new ItemNotOwned($command->itemKey);

        return $this->inventory->transactional(function () use ($item, $command): SaleReceipt {
            $line = $this->inventory->sellOne($command->userId, $item->key);
            if (ItemKind::Equipment !== $item->kind) {
                throw new ItemNotSellable($item->key);
            }
            if ($command->expectedSellPriceCoins !== $item->sellPriceCoins) {
                throw new SalePriceChanged($item->key);
            }
            $before = $this->coins->lockedBalanceOf($command->userId);
            if ($item->sellPriceCoins > 0) {
                $this->coins->credit($command->userId, CoinReason::Sale, Uuid::v7(), $item->sellPriceCoins, $this->clock->now());
            }

            return new SaleReceipt($item->key, $line->quantity(), $item->sellPriceCoins, $before, $this->coins->balanceOf($command->userId));
        });
    }
}
