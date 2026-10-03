<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure\Drop;

use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Domain\ItemKind;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\DroppedItem;
use App\Shared\Application\StreakChests;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Uuid;

/**
 * Le coffre d'une semaine de régularité (#286) arrive à l'inventaire comme un achat : sans
 * tirage, empilé sur les exemplaires déjà possédés. Il s'ouvre ensuite par `OpenChest`.
 *
 * C'est la première source de coffres hors boutique : « personne ne donne de coffre en
 * dehors de la boutique » (#230) ne tient plus, et c'est voulu par la §7.5.
 */
final readonly class StreakChestGrants implements StreakChests
{
    public function __construct(
        private InventoryItemRepository $inventory,
        private ItemCatalog $catalog,
        private ItemTranslator $translator,
    ) {
    }

    public function grant(Uuid $player, string $itemKey, DateTimeImmutable $obtainedAt): DroppedItem
    {
        $chest = $this->catalog->findAvailable($itemKey);
        if (null === $chest || ItemKind::Chest !== $chest->kind) {
            throw new LogicException(\sprintf('Le streak ne peut attribuer que des coffres actifs : "%s".', $itemKey));
        }
        $this->inventory->grantQuantity($player, $itemKey, 1, $obtainedAt);

        return new DroppedItem($chest->key, $chest->kind->value, $this->translator->nameOf($chest->key), $chest->rarity->value, null, [], $chest->priceCoins, $this->translator->imageUrlOf($chest->key));
    }
}
