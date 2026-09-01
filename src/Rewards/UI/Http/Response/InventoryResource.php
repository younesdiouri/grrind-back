<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Response;

use App\Rewards\Application\InventoryOverview;
use App\Rewards\Domain\InventoryItem;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\ItemModifier;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\DroppedItemModifier;
use LogicException;

/**
 * `GET /api/inventory` (#30), et ce que `PUT`/`DELETE /api/inventory/equipment/{slot}`
 * rendent après avoir muté un emplacement — voir le docblock d'`InventoryController` pour
 * pourquoi les trois routes rendent la même forme.
 *
 * **Chaque objet reprend la forme d'{@see DroppedItem}, augmentée d'une seule clé,
 * `quantity`.** Le ticket #30 n'énumère que ce que `DroppedItem` porte déjà — libellé
 * traduit, rareté, emplacement, modificateurs, prix — mais taire combien d'exemplaires un
 * joueur possède ferait de ce sac une liste qui ment par omission sur une donnée déjà en
 * base, pour zéro raison de la cacher. Ce n'est pas une troisième forme d'objet : c'est la
 * même, avec un fait de plus que `DroppedItem` n'a jamais eu à porter — un objet qui tombe
 * n'a jamais de quantité, il en crée ou en incrémente une.
 *
 * **`equipment` n'est pas une seconde lecture de l'inventaire.** Voir le docblock
 * d'{@see InventoryOverview} : c'est la même ligne, réindexée par emplacement, pour que le
 * client n'ait pas à filtrer `items` sur son propre `slot` pour dessiner la poupée
 * d'équipement. Les sept emplacements sont toujours présents, `null` pour un emplacement
 * vide — jamais une clé absente, qui forcerait le client à distinguer « pas encore chargé »
 * de « rien ici ».
 *
 * **`coins` figure ici autant que sur `GET /api/inventory/coins`.** Ce n'est pas une
 * troisième source de vérité qui pourrait diverger : les deux lisent le même
 * {@see \App\Rewards\Application\CoinLedger::balanceOf()} au moment de la requête. Voir le
 * docblock de la classe pour pourquoi cet écran est autant celui de la bourse que celui du
 * sac.
 *
 * **`slot` peut être `null` depuis le #230** : un coffre possédé apparaît dans `items`
 * comme n'importe quel objet, `slot` nul plutôt qu'absent — voir le docblock de
 * {@see DroppedItem}. Il ne figure jamais dans `equipment` : rien ne l'y équipe, voir
 * {@see \App\Rewards\Application\EquipItemHandler}.
 */
final readonly class InventoryResource
{
    /**
     * @param list<array<string, mixed>>               $items
     * @param array<string, array<string, mixed>|null> $equipment
     */
    private function __construct(
        public array $items,
        public array $equipment,
        public int $coins,
    ) {
    }

    public static function from(InventoryOverview $overview, ItemCatalog $catalog, ItemTranslator $translator): self
    {
        return new self(
            array_map(
                static fn (InventoryItem $owned): array => self::describe($owned, $catalog, $translator),
                $overview->items,
            ),
            array_map(
                static fn (?InventoryItem $owned): ?array => null === $owned ? null : self::describe($owned, $catalog, $translator),
                $overview->equipmentBySlot,
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
            'equipment' => $this->equipment,
            'items' => $this->items,
        ];
    }

    /**
     * Une ligne d'inventaire, traduite — voir le docblock de la classe pour pourquoi c'est
     * `DroppedItem` augmentée d'une clé plutôt qu'une forme distincte. Même geste que
     * {@see \App\Rewards\Infrastructure\Drop\WorkoutSessionDrops::describe()} pour la
     * traduction elle-même.
     *
     * @return array<string, mixed>
     */
    private static function describe(InventoryItem $owned, ItemCatalog $catalog, ItemTranslator $translator): array
    {
        $item = $catalog->find($owned->itemKey())
            ?? throw new LogicException(\sprintf('"%s" est possédé par un joueur mais n\'existe plus dans le catalogue.', $owned->itemKey()));

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

        return [...$dropped->toArray(), 'quantity' => $owned->quantity()];
    }
}
