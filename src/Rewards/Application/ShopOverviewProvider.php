<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\Item;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Application\PlayerProgressions;
use Symfony\Component\Uid\Uuid;

/**
 * Assemble {@see ShopOverview} : l'étal de {@see ItemCatalog::shopItems()}, croisé avec ce
 * qu'un joueur précis en sait — toute l'impureté tient ici, aucune décision, même partage
 * qu'entre {@see InventoryOverviewProvider} et l'inventaire.
 *
 * `affordable`, `owned` et `unlocked` se calculent une fois pour tout l'étal plutôt que dans
 * la ressource HTTP : la ressource ne fait alors que traduire et composer, exactement comme
 * {@see \App\Rewards\UI\Http\Response\InventoryResource} le fait pour l'inventaire.
 */
final readonly class ShopOverviewProvider
{
    public function __construct(
        private ItemCatalog $catalog,
        private InventoryItemRepository $inventory,
        private CoinLedger $coins,
        private PlayerProgressions $progressions,
    ) {
    }

    public function of(Uuid $userId): ShopOverview
    {
        $progressions = $this->progressions->of([$userId]);
        $level = $progressions[$userId->toRfc4122()]->level;
        $coins = $this->coins->balanceOf($userId);

        // Un ensemble de clés plutôt qu'une recherche par objet : l'étal ne compte que sept
        // entrées aujourd'hui, mais une requête par objet grandirait avec le catalogue pour
        // rien — une seule lecture de l'inventaire entier suffit.
        $owned = [];
        foreach ($this->inventory->ownedByPlayer($userId) as $line) {
            $owned[$line->itemKey()] = true;
        }

        $entries = array_map(
            static fn (Item $item): ShopEntry => new ShopEntry(
                $item,
                $coins >= $item->priceCoins,
                isset($owned[$item->key]),
                $level >= $item->shopMinimumLevel,
            ),
            $this->catalog->shopItems(),
        );

        return new ShopOverview($entries, $coins);
    }
}
