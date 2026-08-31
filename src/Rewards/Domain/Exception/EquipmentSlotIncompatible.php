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
 *
 * **`$expected` nullable depuis le #230.** Un coffre n'a aucun emplacement — voir le docblock
 * d'{@see \App\Rewards\Domain\Item} — donc tenter de l'équiper n'est pas incompatible avec un
 * *autre* emplacement, il n'y en a aucun avec lequel comparer. Même exception malgré tout,
 * plutôt qu'une quatrième rien que pour ce cas : `EquipItemHandler` la lève déjà dès que
 * `$item->slot !== $slot`, et `null !== $slot` est toujours vrai.
 */
final class EquipmentSlotIncompatible extends RuleViolationError
{
    public function __construct(string $itemKey, ?EquipmentSlot $expected, EquipmentSlot $requested)
    {
        parent::__construct(
            null === $expected
                ? \sprintf('"%s" ne se porte pas, il n\'a aucun emplacement.', $itemKey)
                : \sprintf('"%s" se porte en "%s", pas en "%s".', $itemKey, $expected->value, $requested->value),
            ['itemKey' => $itemKey, 'expectedSlot' => $expected?->value, 'requestedSlot' => $requested->value],
        );
    }

    public function type(): string
    {
        return 'equipment-slot-incompatible';
    }
}
