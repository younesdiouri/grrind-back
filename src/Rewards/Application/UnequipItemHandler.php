<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\Exception\EquipmentSlotUnknown;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;

/**
 * Vide un emplacement — voir le docblock d'{@see UnequipItem} pour pourquoi il se désigne par
 * l'emplacement plutôt que par l'objet. Idempotent, voir
 * {@see InventoryItemRepository::unequip()} : vider un emplacement déjà vide n'est pas un
 * refus.
 */
final readonly class UnequipItemHandler
{
    public function __construct(
        private InventoryItemRepository $inventory,
    ) {
    }

    /** @throws EquipmentSlotUnknown `$command->slot` ne désigne aucun emplacement connu */
    public function __invoke(UnequipItem $command): void
    {
        $slot = EquipmentSlot::tryFrom($command->slot) ?? throw new EquipmentSlotUnknown($command->slot);

        $this->inventory->unequip($command->userId, $slot);
    }
}
