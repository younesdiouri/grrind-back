<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Response;

use App\Rewards\Application\ShopEntry;
use App\Rewards\Application\ShopOverview;
use App\Rewards\Domain\ItemModifier;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\DroppedItemModifier;

/**
 * `GET /api/shop` (#229).
 *
 * **Chaque objet reprend la forme d'{@see DroppedItem}, augmentée de quatre clés** —
 * `affordable`, `owned`, `minimumLevel`, `unlocked` — même geste qu'{@see InventoryResource}
 * pour `quantity` : ce n'est pas une quatrième forme d'objet, c'est la même, avec ce que
 * {@see ShopOverview} sait déjà calculé pour ce joueur précis.
 *
 * **`coins` figure ici comme sur `GET /api/inventory`.** Ce n'est pas une troisième source de
 * vérité : les deux lisent le même {@see \App\Rewards\Application\CoinLedger::balanceOf()} au
 * moment de la requête — même remarque que sur `InventoryResource`.
 *
 * **Un objet verrouillé par le niveau reste dans la liste.** L'étal ne cache jamais ce que le
 * joueur ne peut pas encore acheter — `unlocked: false` le dit, plutôt que de l'omettre.
 *
 * **`slot` peut être `null` depuis le #230** : un coffre est un objet de l'étal comme un
 * autre, distingué par `kind` côté catalogue mais pas encore exposé côté contrat — voir le
 * docblock de {@see DroppedItem}. Le contenu d'un coffre n'apparaît jamais ici : cette
 * ressource ne fait que traduire ce que le catalogue affirme (nom, rareté, prix), jamais ce
 * qu'une table de tirage promet.
 */
final readonly class ShopResource
{
    /**
     * @param list<array<string, mixed>> $items
     */
    private function __construct(
        public array $items,
        public int $coins,
    ) {
    }

    public static function from(ShopOverview $overview, ItemTranslator $translator): self
    {
        return new self(
            array_map(
                static fn (ShopEntry $entry): array => self::describe($entry, $translator),
                $overview->entries,
            ),
            $overview->coins,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'coins' => $this->coins,
            'items' => $this->items,
        ];
    }

    /**
     * Un objet de l'étal, traduit — même geste que
     * {@see \App\Rewards\Infrastructure\Drop\WorkoutSessionDrops::describe()} pour la
     * traduction elle-même.
     *
     * @return array<string, mixed>
     */
    private static function describe(ShopEntry $entry, ItemTranslator $translator): array
    {
        $item = $entry->item;

        $dropped = new DroppedItem(
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
        );

        return [
            ...$dropped->toArray(),
            'affordable' => $entry->affordable,
            'owned' => $entry->owned,
            'minimumLevel' => $item->shopMinimumLevel,
            'unlocked' => $entry->unlocked,
        ];
    }
}
