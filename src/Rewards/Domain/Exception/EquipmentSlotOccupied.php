<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Rewards\Domain\EquipmentSlot;
use App\Shared\Domain\Exception\RuleViolationError;

/**
 * L'emplacement visé porte déjà un **autre** objet (#29) — « équiper un objet déjà équipé
 * ailleurs », dans les mots du ticket : ce n'est pas l'objet qu'on équipe qui est ailleurs,
 * c'est l'emplacement qui l'est déjà par un autre. `Un objet par emplacement` est l'invariant
 * que {@see \App\Rewards\Domain\InventoryItem} tient en base par une unicité partielle ; cette
 * exception est ce qui l'empêche de se manifester par une contrainte SQL brute plutôt que par
 * un refus lisible.
 *
 * **Aucun échange implicite.** Le joueur déséquipe l'occupant d'abord — une action distincte,
 * délibérée — plutôt que de laisser cette commande décider pour lui de ce qui retourne dans
 * le sac.
 */
final class EquipmentSlotOccupied extends RuleViolationError
{
    public function __construct(string $itemKey, string $occupiedBy, EquipmentSlot $slot)
    {
        parent::__construct(
            \sprintf('"%s" porte déjà "%s" : déséquiper d\'abord.', $slot->value, $occupiedBy),
            ['itemKey' => $itemKey, 'slot' => $slot->value, 'occupiedBy' => $occupiedBy],
        );
    }

    public function type(): string
    {
        return 'equipment-slot-occupied';
    }
}
