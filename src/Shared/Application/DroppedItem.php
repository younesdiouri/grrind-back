<?php

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * Un objet tombé, tel que le client le reçoit — la seule forme JSON d'un objet dans le
 * `RewardSummary` (#226), même geste que {@see PlayerTitle} pour un titre : traduit,
 * décrit, prêt à afficher **sans requête supplémentaire**.
 *
 * `rarity`, `slot` et `kind` sont des chaînes, pas les enums d'`App\Rewards\Domain` : `Shared`
 * ne connaît pas `Rewards`, seulement le vocabulaire que ce module choisit d'exposer, exactement
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
 *
 * **`kind` s'ajoute au contrat dans le même geste, en revue de la PR du #230.** `slot === null`
 * suffisait à distinguer un coffre tant qu'il était le seul objet sans emplacement, mais c'est
 * une déduction que le client aurait dû écrire à la main, dans un fichier que le contrat ne
 * génère pas — et elle se serait tue en silence au premier objet non équipable de plus (un
 * consommable, une monnaie de faction). `kind` porte donc la nature de l'objet explicitement,
 * une valeur d'`App\Rewards\Domain\ItemKind`, pour que l'app décide « Ouvrir » ou « Équiper »
 * sur une donnée du contrat plutôt que sur une absence de valeur.
 */
final readonly class DroppedItem
{
    /**
     * @param list<DroppedItemModifier> $modifiers dans l'ordre du catalogue
     */
    public function __construct(
        public string $key,
        /** Une valeur de `App\Rewards\Domain\ItemKind` (#230) — ce que l'objet *est*, avant même son nom. */
        public string $kind,
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
            'kind' => $this->kind,
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
