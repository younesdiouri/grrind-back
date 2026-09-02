<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Response;

use App\Rewards\Application\ChestOpenReceipt;
use App\Rewards\Domain\Item;
use App\Rewards\Domain\ItemModifier;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\DroppedItemModifier;

/**
 * `POST /api/inventory/chests/{key}/open` (#230) : ce que le coffre ouvert a rendu — même
 * forme qu'un drop de séance ou de combat, parce que c'en est un, voir le docblock
 * d'{@see ChestOpenReceipt}.
 *
 * **`items` est vide le plus souvent**, comme sur `SessionDrop`/`BattleDrop` — voir le
 * docblock de {@see \App\Rewards\Domain\LootRoller}. Le contenu d'un coffre ne se révèle
 * qu'ici : ni `GET /api/inventory` ni `GET /api/shop` ne rendent la table qui l'alimente.
 */
final readonly class ChestOpenResource
{
    /**
     * @param list<array<string, mixed>> $items
     */
    private function __construct(
        public array $items,
        public int $coins,
        public int $coinsBefore,
        public int $coinsAfter,
    ) {
    }

    public static function from(ChestOpenReceipt $receipt, ItemTranslator $translator): self
    {
        return new self(
            array_map(
                static fn (Item $item): array => self::describe($item, $translator),
                $receipt->items,
            ),
            $receipt->coins,
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
            'items' => $this->items,
            'coins' => $this->coins,
            'coinsBefore' => $this->coinsBefore,
            'coinsAfter' => $this->coinsAfter,
        ];
    }

    /**
     * Même geste que {@see PurchaseResource} pour la traduction elle-même — un objet tombé
     * d'un coffre porte toujours un emplacement non nul : un coffre ne peut pas contenir de
     * coffre, voir le docblock de {@see \App\Rewards\Domain\LootTables}.
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
            $translator->imageUrlOf($item->key),
        )->toArray();
    }
}
