<?php

declare(strict_types=1);

namespace App\Rewards\Infrastructure;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Rewards\UI\Http\Response\InventoryResource;
use App\Shared\Application\PublicInventories;
use Symfony\Component\Uid\Uuid;

/** Lecture publique sans consulter la bourse, réservée au profil détaillé pour éviter un N+1 en guilde. */
final readonly class PublicPlayerInventories implements PublicInventories
{
    public function __construct(
        private InventoryItemRepository $items,
        private ItemCatalog $catalog,
        private ItemTranslator $translator,
    ) {
    }

    public function of(Uuid $playerId): array
    {
        $equipment = [];
        foreach (EquipmentSlot::cases() as $slot) {
            $equipment[$slot->value] = null;
        }
        $items = [];
        foreach ($this->items->ownedByPlayer($playerId) as $owned) {
            $item = InventoryResource::describePublic($owned, $this->catalog, $this->translator);
            $items[] = $item;
            if (null !== $owned->slot()) {
                $equipment[$owned->slot()->value] = $item;
            }
        }

        return ['equipment' => $equipment, 'items' => $items];
    }
}
