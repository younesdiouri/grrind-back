<?php

declare(strict_types=1);

namespace App\Rewards\Domain\Exception;

use App\Rewards\Domain\EquipmentSlot;
use App\Shared\Domain\Exception\RuleViolationError;

/**
 * L'emplacement demandé n'est pas celui que le catalogue assigne à cet objet (#29) — des
 * bottes qu'on tente de porter en `WEAPON`, par exemple. La compatibilité est **portée par le
 * catalogue** ({@see \App\Rewards\Domain\Item::$slot}), jamais choisie par le joueur : cette
 * exception protège cette règle plutôt que de la documenter seulement.
 */
final class EquipmentSlotIncompatible extends RuleViolationError
{
    public function __construct(string $itemKey, EquipmentSlot $expected, EquipmentSlot $requested)
    {
        parent::__construct(
            \sprintf('"%s" se porte en "%s", pas en "%s".', $itemKey, $expected->value, $requested->value),
            ['itemKey' => $itemKey, 'expectedSlot' => $expected->value, 'requestedSlot' => $requested->value],
        );
    }

    public function type(): string
    {
        return 'equipment-slot-incompatible';
    }
}
