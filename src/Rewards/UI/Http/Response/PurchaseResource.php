<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Response;

use App\Rewards\Application\PurchaseReceipt;
use App\Rewards\Domain\Item;
use App\Rewards\Domain\ItemModifier;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\DroppedItemModifier;

/**
 * `POST /api/shop/purchases` (#229) : l'objet acheté — sous la forme habituelle de
 * {@see DroppedItem} — ce qu'il a coûté, et le solde avant *et* après, comme un
 * `RewardSummary` porte ses deux paliers : la bourse se décrémente à l'écran sans que le
 * client recalcule quoi que ce soit.
 *
 * **`slot` peut être `null` depuis le #230** : un coffre s'achète comme n'importe quel objet
 * de l'étal — voir le docblock de {@see DroppedItem}.
 */
final readonly class PurchaseResource
{
    /**
     * @param array<string, mixed> $item
     */
    private function __construct(
        public array $item,
        public int $spentCoins,
        public int $coinsBefore,
        public int $coinsAfter,
    ) {
    }

    public static function from(PurchaseReceipt $receipt, ItemTranslator $translator): self
    {
        return new self(
            self::describe($receipt->item, $translator),
            $receipt->spentCoins,
            $receipt->coinsBefore,
            $receipt->coinsAfter,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item' => $this->item,
            'spentCoins' => $this->spentCoins,
            'coinsBefore' => $this->coinsBefore,
            'coinsAfter' => $this->coinsAfter,
        ];
    }

    /**
     * Même geste que {@see ShopResource} pour la traduction elle-même — pas factorisé entre
     * les deux : chaque ressource compose sa propre forme, comme
     * {@see \App\Rewards\Infrastructure\Drop\WorkoutSessionDrops} et {@see InventoryResource}
     * le font déjà sans se partager de code.
     *
     * @return array<string, mixed>
     */
    private static function describe(Item $item, ItemTranslator $translator): array
    {
        return new DroppedItem(
            $item->key,
            $item->kind->value,
            $translator->nameOf($item->key),
            $item->rarity->value,
            $item->slot?->value,
            array_map(
                static fn (ItemModifier $modifier): DroppedItemModifier => new DroppedItemModifier(
                    $modifier->type->value,
                    $modifier->value,
                    $modifier->discipline?->value,
                ),
                $item->modifiers,
            ),
            $item->priceCoins,
        )->toArray();
    }
}
