<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\InventoryItem;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le moteur de jeu se teste sans aucune infra — même geste que `ItemCatalogTest` : cette
 * classe ne touche ni au conteneur ni à la base, contrairement à `InventoryPersistenceTest`
 * qui prouve le mapping.
 */
final class InventoryItemTest extends TestCase
{
    public function testFirstGrantStartsAtQuantityOneWithNoSlot(): void
    {
        $lootRollId = Uuid::v7();
        $obtainedAt = new DateTimeImmutable('2026-08-30T12:00:00+00:00');

        $item = InventoryItem::firstGrant(Uuid::v7(), 'WORN_RUNNING_SHOES', $lootRollId, $obtainedAt);

        self::assertSame(1, $item->quantity());
        self::assertNull($item->slot());
        self::assertTrue($lootRollId->equals($item->lootRollId()));
        self::assertEquals($obtainedAt, $item->obtainedAt());
    }

    /**
     * `$quantity` ne redescend jamais dans ce ticket (#29) : la seule mutation possible est
     * additive, et elle glisse la provenance vers le tirage le plus récent — voir le docblock
     * de la classe.
     */
    public function testGrantOneMoreIncrementsAndTracksTheMostRecentRoll(): void
    {
        $item = InventoryItem::firstGrant(Uuid::v7(), 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable('2026-08-01T08:00:00+00:00'));

        $secondRollId = Uuid::v7();
        $secondObtainedAt = new DateTimeImmutable('2026-08-15T08:00:00+00:00');
        $item->grantOneMore($secondRollId, $secondObtainedAt);

        self::assertSame(2, $item->quantity());
        self::assertTrue($secondRollId->equals($item->lootRollId()));
        self::assertEquals($secondObtainedAt, $item->obtainedAt());
    }

    public function testEquipIntoSetsTheSlot(): void
    {
        $item = InventoryItem::firstGrant(Uuid::v7(), 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());

        $item->equipInto(EquipmentSlot::Hands);

        self::assertSame(EquipmentSlot::Hands, $item->slot());
    }

    public function testUnequipSendsTheItemBackToTheBag(): void
    {
        $item = InventoryItem::firstGrant(Uuid::v7(), 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());
        $item->equipInto(EquipmentSlot::Hands);

        $item->unequip();

        self::assertNull($item->slot());
    }

    /** Idempotent : déséquiper un objet déjà dans le sac ne provoque aucune erreur. */
    public function testUnequippingAnItemAlreadyInTheBagIsANoOp(): void
    {
        $item = InventoryItem::firstGrant(Uuid::v7(), 'IRON_GAUNTLETS', Uuid::v7(), new DateTimeImmutable());

        $item->unequip();

        self::assertNull($item->slot());
    }
}
