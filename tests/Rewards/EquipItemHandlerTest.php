<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\EquipItem;
use App\Rewards\Application\EquipItemHandler;
use App\Rewards\Application\UnequipItem;
use App\Rewards\Application\UnequipItemHandler;
use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\Exception\EquipmentSlotIncompatible;
use App\Rewards\Domain\Exception\EquipmentSlotUnknown;
use App\Rewards\Domain\Exception\ItemNotOwned;
use App\Rewards\Domain\InventoryItem;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Les commandes/handlers d'équipement (#29) — le point d'entrée applicatif que la future
 * route (#30) appellera. Contre la vraie base : `EquipItemHandler` traverse le catalogue réel
 * *et* l'inventaire réel, et c'est la combinaison des deux qui produit chacun des trois refus.
 *
 * **Les deux handlers, et `ItemCatalog`, se construisent directement, comme `CoinLedger` dans
 * `CoinLedgerPersistenceTest`.** Aucune route ne les appelle encore (#30) : le compilateur
 * retirerait un service que rien d'autre ne référence si on allait le chercher par son id.
 * `ItemCatalog` n'a lui-même aucun consommateur en production — `ItemTranslator` traduit
 * depuis la clé brute, jamais depuis le catalogue, voir son docblock — donc `RewardsCoverageTest`
 * le reconstruit déjà depuis le paramètre brut plutôt que de le résoudre ; ce test fait de
 * même. Seule `InventoryItemRepository` reste un service du conteneur : Doctrine la garde
 * accessible indépendamment de tout consommateur applicatif, même geste que
 * `LootRollRepository` avant le #226/#227.
 */
final class EquipItemHandlerTest extends ApiTestCase
{
    public function testEquippingAnOwnedCompatibleItemPersistsTheSlot(): void
    {
        $userId = Uuid::v7();
        self::repository()->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());

        $equipped = (self::equip())(new EquipItem($userId, 'IRON_GAUNTLETS', 'HANDS'));

        self::assertInstanceOf(InventoryItem::class, $equipped);
        self::assertSame(EquipmentSlot::Hands, $equipped->slot());
    }

    public function testEquippingAnUnknownSlotStringIsRefused(): void
    {
        $userId = Uuid::v7();
        self::repository()->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());

        $this->expectException(EquipmentSlotUnknown::class);

        (self::equip())(new EquipItem($userId, 'IRON_GAUNTLETS', 'TAIL'));
    }

    public function testEquippingAnItemNotInInventoryIsRefused(): void
    {
        $this->expectException(ItemNotOwned::class);

        (self::equip())(new EquipItem(Uuid::v7(), 'IRON_GAUNTLETS', 'HANDS'));
    }

    public function testEquippingAnUnknownCatalogKeyIsRefusedAsNotOwned(): void
    {
        $this->expectException(ItemNotOwned::class);

        (self::equip())(new EquipItem(Uuid::v7(), 'DOES_NOT_EXIST', 'HANDS'));
    }

    /**
     * `IRON_GAUNTLETS` se porte en `HANDS`, jamais en `HEAD` : le catalogue tranche, pas le
     * joueur — voir le docblock d'`EquipItemHandler`.
     */
    public function testEquippingIntoAnIncompatibleSlotIsRefused(): void
    {
        $userId = Uuid::v7();
        self::repository()->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());

        $this->expectException(EquipmentSlotIncompatible::class);

        (self::equip())(new EquipItem($userId, 'IRON_GAUNTLETS', 'HEAD'));
    }

    public function testUnequippingClearsTheSlot(): void
    {
        $userId = Uuid::v7();
        self::repository()->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());
        (self::equip())(new EquipItem($userId, 'IRON_GAUNTLETS', 'HANDS'));

        (self::unequip())(new UnequipItem($userId, 'HANDS'));

        self::assertNull(self::repository()->equippedIn($userId, EquipmentSlot::Hands));
    }

    public function testUnequippingAnUnknownSlotStringIsRefused(): void
    {
        $this->expectException(EquipmentSlotUnknown::class);

        (self::unequip())(new UnequipItem(Uuid::v7(), 'TAIL'));
    }

    private static function equip(): EquipItemHandler
    {
        return new EquipItemHandler(self::repository(), self::catalog());
    }

    private static function unequip(): UnequipItemHandler
    {
        return new UnequipItemHandler(self::repository());
    }

    private static function repository(): InventoryItemRepository
    {
        $repository = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $repository);

        return $repository;
    }

    private static function catalog(): ItemCatalog
    {
        $items = self::getContainer()->getParameter('game.items.items');
        self::assertIsArray($items);

        /** @var list<array{key: string, rarity: string, slot: string, price_coins: int, modifiers: list<array{type: string, value: int, discipline?: string}>}> $items */
        return new ItemCatalog($items);
    }
}
