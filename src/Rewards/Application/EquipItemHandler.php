<?php

declare(strict_types=1);

namespace App\Rewards\Application;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\Exception\EquipmentSlotIncompatible;
use App\Rewards\Domain\Exception\EquipmentSlotUnknown;
use App\Rewards\Domain\Exception\ItemNotOwned;
use App\Rewards\Domain\InventoryItem;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;

/**
 * Équipe un objet, avec ses trois refus, chacun une exception de domaine plutôt qu'une
 * réponse HTTP — c'est la future route (#30) qui les traduira en RFC 7807, pas cette classe.
 *
 * ## L'ordre des vérifications, et pourquoi
 *
 * 1. `$command->slot` se résout d'abord contre {@see EquipmentSlot} — une chaîne qui ne
 *    désigne rien ne mérite pas d'ouvrir une transaction pour le découvrir ;
 * 2. `$command->itemKey` se résout contre {@see ItemCatalog} — une clé inconnue ne peut par
 *    construction être possédée par personne, elle emprunte donc {@see ItemNotOwned} plutôt
 *    qu'une quatrième exception, voir son docblock ;
 * 3. la compatibilité — `$item->slot` est l'unique emplacement où le catalogue autorise cet
 *    objet, voir {@see \App\Rewards\Domain\Item} — se vérifie ensuite, **avant** le verrou :
 *    c'est une lecture pure du catalogue, elle n'a besoin d'aucune transaction ;
 * 4. la possession et l'occupation de l'emplacement, les deux seules vérifications qui
 *    dépendent d'un état mutable, se font **sous verrou** dans
 *    {@see InventoryItemRepository::equip()} — voir son docblock.
 */
final readonly class EquipItemHandler
{
    public function __construct(
        private InventoryItemRepository $inventory,
        private ItemCatalog $catalog,
    ) {
    }

    /**
     * @throws EquipmentSlotUnknown      `$command->slot` ne désigne aucun emplacement connu
     * @throws ItemNotOwned              `$command->itemKey` est inconnu du catalogue, ou non possédé
     * @throws EquipmentSlotIncompatible l'objet ne se porte pas dans l'emplacement demandé
     */
    public function __invoke(EquipItem $command): InventoryItem
    {
        $slot = EquipmentSlot::tryFrom($command->slot) ?? throw new EquipmentSlotUnknown($command->slot);

        $item = $this->catalog->find($command->itemKey) ?? throw new ItemNotOwned($command->itemKey);

        if ($item->slot !== $slot) {
            throw new EquipmentSlotIncompatible($item->key, $item->slot, $slot);
        }

        return $this->inventory->equip($command->userId, $item, $slot);
    }
}
