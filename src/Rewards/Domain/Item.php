<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

/**
 * Un objet du catalogue — pas une ligne d'inventaire : rien ici n'appartient à un joueur,
 * voir le docblock d'{@see ItemCatalog}.
 */
final readonly class Item
{
    /**
     * @param list<ItemModifier> $modifiers vide pour un coffre — {@see ItemCatalog} le refuse sinon
     */
    public function __construct(
        public string $key,
        public Rarity $rarity,
        /**
         * `null` pour un coffre (#230) — il ne se porte pas, il s'ouvre. `ItemCatalog` refuse
         * un `EQUIPMENT` sans emplacement et un `CHEST` qui en poserait un, donc la nullité
         * ici suit exactement `$kind` : jamais les deux à la fois, jamais ni l'un ni l'autre.
         */
        public ?EquipmentSlot $slot,
        /** En pièces — voir le docblock d'le snapshot publié pour pourquoi il a existé avant la boutique (#229). */
        public int $priceCoins,
        public array $modifiers,
        /**
         * `EQUIPMENT` par défaut (#230) : les neuf objets livrés avant ce ticket n'ont rien eu
         * à déclarer pour le rester. Voir le docblock d'{@see ItemKind}.
         */
        public ItemKind $kind = ItemKind::Equipment,
        /**
         * `false` par défaut : la plupart des appels — les fixtures de test, un objet qui
         * tombe d'un tirage — n'ont jamais eu à parler de boutique. `ItemCatalog` est le seul
         * qui la pose sciemment, depuis le bloc `shop:` d'le snapshot publié — voir son docblock
         * pour les deux règles qu'il vérifie avant d'accepter `true`.
         */
        public bool $shopAvailable = false,
        /**
         * Sans effet si `$shopAvailable` est `false`. `1` par défaut — n'importe quel joueur
         * inscrit satisfait ce plancher, un objet à l'étal sans `minimum_level` déclaré dans
         * le snapshot publié n'a donc aucun verrou de niveau.
         */
        public int $shopMinimumLevel = 1,
    ) {
    }
}
