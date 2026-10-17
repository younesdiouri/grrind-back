<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure;

use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Application\PlayerEquipment;

/** Les références sont figées au combat ; relire un replay ne consulte jamais l'inventaire. */
final readonly class EquippedPlayerItems implements PlayerEquipment
{
    public function __construct(private InventoryItemRepository $items)
    {
    }

    public function of(array $playerIds): array
    {
        $result = [];
        foreach ($playerIds as $id) {
            $result[$id->toRfc4122()] = [];
        }
        foreach ($this->items->equippedByPlayers($playerIds) as $item) {
            $slot = $item->slot();
            \assert(null !== $slot);
            $result[$item->userId()->toRfc4122()][$slot->value] = [
                'key' => $item->itemKey(),
                // shortcut: premier pack embarqué, lié aux ancrages Murīd v2 ; publier le catalogue à la génération massive.
                'artworkVersion' => 'TEMPERED_HEADBAND' === $item->itemKey() ? 'v1' : null,
            ];
        }

        return $result;
    }
}
