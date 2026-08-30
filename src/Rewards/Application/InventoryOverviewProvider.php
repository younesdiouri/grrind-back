<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Assemble {@see InventoryOverview} : lit l'inventaire entier, le solde de pièces, et pose ce
 * qui est équipé sous chacun des sept emplacements — toute l'impureté tient ici, aucune
 * décision, même partage qu'entre {@see \App\Progression\Application\TitleBoardProvider} et
 * le catalogue de titres.
 */
final readonly class InventoryOverviewProvider
{
    public function __construct(
        private InventoryItemRepository $items,
        private CoinLedger $coins,
    ) {
    }

    public function of(Uuid $userId): InventoryOverview
    {
        $owned = $this->items->ownedByPlayer($userId);

        // Les sept emplacements sont toujours présents, vides ou non : un client qui dessine
        // une poupée d'équipement n'a pas à savoir lesquels existent, `EquipmentSlot` le lui
        // dit une fois pour toutes.
        $equipmentBySlot = [];
        foreach (EquipmentSlot::cases() as $slot) {
            $equipmentBySlot[$slot->value] = null;
        }

        foreach ($owned as $item) {
            if (null !== $item->slot()) {
                $equipmentBySlot[$item->slot()->value] = $item;
            }
        }

        return new InventoryOverview($owned, $equipmentBySlot, $this->coins->balanceOf($userId));
    }
}
