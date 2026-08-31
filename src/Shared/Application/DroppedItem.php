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
 *
 * **`slot` est nullable depuis le #230 — un changement de contrat client.** Un coffre n'a pas
 * d'emplacement, et `GET /api/inventory` peut en montrer un possédé : la forme exposée doit
 * donc accepter `null`, là où elle ne le faisait pas avant ce ticket. Un coffre ne tombe en
 * revanche jamais d'un tirage — voir « Personne ne donne de coffre en dehors de la boutique »
 * au #230 — donc {@see \App\Rewards\Infrastructure\Drop\WorkoutSessionDrops::describe()} et
 * {@see \App\Rewards\Infrastructure\Drop\AdversaryBattleDrops::describe()}, les deux seuls
 * chemins qui construisent cette classe depuis un tirage, ont le droit d'exiger un
 * emplacement non nul et de lever s'ils en rencontraient un — voir leur docblock.
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
        /** Une valeur de `App\Rewards\Domain\EquipmentSlot`, ou `null` pour un coffre (#230). */
        public ?string $slot,
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
