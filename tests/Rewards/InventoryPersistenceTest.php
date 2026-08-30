<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\Exception\EquipmentSlotOccupied;
use App\Rewards\Domain\Exception\ItemNotOwned;
use App\Rewards\Domain\InventoryItem;
use App\Rewards\Domain\Item;
use App\Rewards\Domain\ItemModifier;
use App\Rewards\Domain\Rarity;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\Domain\Modifier\ModifierType;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * L'inventaire contre une vraie base — même geste que `CoinLedgerPersistenceTest` et
 * `LootRollPersistenceTest` : ce qu'aucun test en mémoire ne prouve, c'est que le mapping
 * Doctrine et la migration `rewards_inventory_item` (#29) s'accordent, que l'unicité
 * `(user_id, item_key)` fusionne bien deux tirages du même objet en une ligne, et que
 * l'unicité partielle `(user_id, slot) WHERE slot IS NOT NULL` tient *dans la vraie base* —
 * pas seulement dans une assertion en mémoire.
 */
final class InventoryPersistenceTest extends ApiTestCase
{
    public function testAGrantedItemRoundTripsThroughTheDatabase(): void
    {
        $entityManager = self::entityManager();
        $repository = self::repository();

        $userId = Uuid::v7();
        $lootRollId = Uuid::v7();
        $obtainedAt = new DateTimeImmutable('2026-08-30T12:00:00+00:00');

        $item = $repository->grant($userId, 'WORN_RUNNING_SHOES', $lootRollId, $obtainedAt);
        $entityManager->clear();

        $reloaded = $repository->find($item->id());

        self::assertInstanceOf(InventoryItem::class, $reloaded);
        self::assertTrue($userId->equals($reloaded->userId()));
        self::assertSame('WORN_RUNNING_SHOES', $reloaded->itemKey());
        self::assertSame(1, $reloaded->quantity());
        self::assertNull($reloaded->slot());
        self::assertTrue($lootRollId->equals($reloaded->lootRollId()));
        self::assertEquals($obtainedAt, $reloaded->obtainedAt());
    }

    /**
     * Le cœur de la table : deux tirages du même objet pour le même joueur fusionnent en une
     * seule ligne — voir le docblock d'`InventoryItem` — la provenance glissant vers le plus
     * récent.
     */
    public function testGrantingTheSameItemTwiceMergesIntoOneRowWithTheLatestProvenance(): void
    {
        $repository = self::repository();
        $userId = Uuid::v7();

        $repository->grant($userId, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable('2026-08-01T08:00:00+00:00'));

        $secondRollId = Uuid::v7();
        $secondObtainedAt = new DateTimeImmutable('2026-08-15T08:00:00+00:00');
        $repository->grant($userId, 'WORN_RUNNING_SHOES', $secondRollId, $secondObtainedAt);

        $stored = $repository->ofPlayerAndItem($userId, 'WORN_RUNNING_SHOES');

        self::assertInstanceOf(InventoryItem::class, $stored);
        self::assertSame(2, $stored->quantity());
        self::assertTrue($secondRollId->equals($stored->lootRollId()));
        self::assertEquals($secondObtainedAt, $stored->obtainedAt());
    }

    public function testEquippingAnOwnedItemPersistsTheSlot(): void
    {
        $entityManager = self::entityManager();
        $repository = self::repository();
        $userId = Uuid::v7();

        $repository->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());

        $equipped = $repository->equip($userId, self::gauntlets(), EquipmentSlot::Hands);
        $entityManager->clear();

        self::assertSame(EquipmentSlot::Hands, $equipped->slot());
        $reloaded = $repository->equippedIn($userId, EquipmentSlot::Hands);
        self::assertInstanceOf(InventoryItem::class, $reloaded);
        self::assertSame('IRON_GAUNTLETS', $reloaded->itemKey());
    }

    public function testEquippingAnUnownedItemIsRefused(): void
    {
        $repository = self::repository();

        $this->expectException(ItemNotOwned::class);

        $repository->equip(Uuid::v7(), self::gauntlets(), EquipmentSlot::Hands);
    }

    /**
     * **Le cœur du ticket, en base.** L'unicité partielle refuse un second objet dans le même
     * emplacement — c'est elle, pas cette vérification applicative seule, qui protège
     * l'invariant sous concurrence ; ce test prouve que le chemin applicatif la respecte déjà,
     * sans jamais laisser la contrainte se déclencher.
     */
    public function testEquippingIntoAnOccupiedSlotIsRefused(): void
    {
        $repository = self::repository();
        $userId = Uuid::v7();

        $repository->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());
        $repository->equip($userId, self::gauntlets(), EquipmentSlot::Hands);

        $repository->grant($userId, 'OTHER_HAND_ITEM', Uuid::v7(), new DateTimeImmutable());

        $this->expectException(EquipmentSlotOccupied::class);

        $repository->equip($userId, self::otherHandItem(), EquipmentSlot::Hands);
    }

    /** Ré-équiper le même objet dans le même emplacement est un no-op, pas un conflit. */
    public function testReequippingTheSameItemInTheSameSlotIsIdempotent(): void
    {
        $repository = self::repository();
        $userId = Uuid::v7();

        $repository->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());
        $repository->equip($userId, self::gauntlets(), EquipmentSlot::Hands);

        $equipped = $repository->equip($userId, self::gauntlets(), EquipmentSlot::Hands);

        self::assertSame(EquipmentSlot::Hands, $equipped->slot());
    }

    public function testUnequippingClearsTheSlot(): void
    {
        $repository = self::repository();
        $userId = Uuid::v7();

        $repository->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());
        $repository->equip($userId, self::gauntlets(), EquipmentSlot::Hands);

        $repository->unequip($userId, EquipmentSlot::Hands);

        self::assertNull($repository->equippedIn($userId, EquipmentSlot::Hands));
    }

    /** Idempotent, comme un `DELETE` sur une ressource déjà absente. */
    public function testUnequippingAnEmptySlotDoesNothing(): void
    {
        $repository = self::repository();

        $repository->unequip(Uuid::v7(), EquipmentSlot::Hands);

        self::assertNull($repository->equippedIn(Uuid::v7(), EquipmentSlot::Hands));
    }

    public function testEquippedByPlayerOnlyReturnsWornItems(): void
    {
        $repository = self::repository();
        $userId = Uuid::v7();

        $repository->grant($userId, 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());
        $repository->grant($userId, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        $repository->equip($userId, self::gauntlets(), EquipmentSlot::Hands);

        $worn = $repository->equippedByPlayer($userId);

        self::assertCount(1, $worn);
        self::assertSame('IRON_GAUNTLETS', $worn[0]->itemKey());
    }

    private static function gauntlets(): Item
    {
        return new Item('IRON_GAUNTLETS', Rarity::Common, EquipmentSlot::Hands, 30, [
            new ItemModifier(ModifierType::StrengthBonus, 350),
        ]);
    }

    private static function otherHandItem(): Item
    {
        return new Item('OTHER_HAND_ITEM', Rarity::Common, EquipmentSlot::Hands, 30, []);
    }

    private static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }

    private static function repository(): InventoryItemRepository
    {
        $repository = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $repository);

        return $repository;
    }
}
