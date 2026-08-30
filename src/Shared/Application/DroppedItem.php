<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Un objet tombé, tel que le client le reçoit — la seule forme JSON d'un objet dans le
 * `RewardSummary` (#226), même geste que {@see PlayerTitle} pour un titre : traduit,
 * décrit, prêt à afficher **sans requête supplémentaire**.
 *
 * `rarity` et `slot` sont des chaînes, pas les enums d'`App\Rewards\Domain` : `Shared` ne
 * connaît pas `Rewards`, seulement le vocabulaire que ce module choisit d'exposer, exactement
 * comme `XpLine::$source` pour `Progression`.
 */
final readonly class DroppedItem
{
    /**
     * @param list<DroppedItemModifier> $modifiers dans l'ordre du catalogue
     */
    public function __construct(
        public string $key,
        /** Déjà traduit dans la langue du joueur — rien à recharger côté client. */
        public string $name,
        /** Une valeur de `App\Rewards\Domain\Rarity`. */
        public string $rarity,
        /** Une valeur de `App\Rewards\Domain\EquipmentSlot`. */
        public string $slot,
        public array $modifiers,
        /** En pièces — de quoi afficher la valeur de l'objet sans recharger le catalogue. */
        public int $priceCoins,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'rarity' => $this->rarity,
            'slot' => $this->slot,
            'modifiers' => array_map(
                static fn (DroppedItemModifier $modifier): array => $modifier->toArray(),
                $this->modifiers,
            ),
            'priceCoins' => $this->priceCoins,
        ];
    }
}
