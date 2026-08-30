<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Un effet porté par un objet tombé, tel que le client le reçoit — le pendant de
 * {@see XpLine} pour {@see DroppedItem}, même raison de ne porter que des scalaires : ni
 * `Rewards\Domain\ItemModifier` ni son enum `ModifierType` ne traversent la frontière, une
 * chaîne suffit à l'afficher.
 */
final readonly class DroppedItemModifier
{
    public function __construct(
        /** Une valeur de `App\Shared\Domain\Modifier\ModifierType`. */
        public string $type,
        public int $value,
        /** `null` = global, comme `App\Shared\Domain\Modifier\Modifier::$discipline`. */
        public ?string $discipline,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
            'discipline' => $this->discipline,
        ];
    }
}
