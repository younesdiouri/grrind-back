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
     * additive, et elle ne touche pas à la provenance — voir le docblock de la classe pour
     * pourquoi la date d'obtention reste celle de la toute première acquisition.
     */
    public function testGrantOneMoreIncrementsWithoutTouchingTheProvenance(): void
    {
        $firstRollId = Uuid::v7();
        $firstObtainedAt = new DateTimeImmutable('2026-08-01T08:00:00+00:00');
        $item = InventoryItem::firstGrant(Uuid::v7(), 'WORN_RUNNING_SHOES', $firstRollId, $firstObtainedAt);

        $item->grantOneMore();

        self::assertSame(2, $item->quantity());
        self::assertTrue($firstRollId->equals($item->lootRollId()));
        self::assertEquals($firstObtainedAt, $item->obtainedAt());
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

    /**
     * `null` = acquis autrement qu'au tirage (#229) — un achat, aujourd'hui la seule autre
     * voie. Voir le docblock de la classe.
     */
    public function testFirstGrantAcceptsANullLootRollId(): void
    {
        $item = InventoryItem::firstGrant(Uuid::v7(), 'WORN_RUNNING_SHOES', null, new DateTimeImmutable());

        self::assertNull($item->lootRollId());
    }
}
