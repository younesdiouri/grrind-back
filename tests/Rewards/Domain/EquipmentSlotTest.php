<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\EquipmentSlot;
use App\Rewards\Domain\ItemCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EquipmentSlotTest extends TestCase
{
    public function testTheActiveDomainHasSixSlotsAndRejectsHands(): void
    {
        self::assertSame(['HEAD', 'CHEST', 'LEGS', 'FEET', 'ACCESSORY', 'WEAPON'], array_column(EquipmentSlot::cases(), 'value'));
        self::assertNull(EquipmentSlot::tryFrom('HANDS'));
        $this->expectException(InvalidArgumentException::class);
        new ItemCatalog([['key' => 'OLD_GLOVES', 'rarity' => 'COMMON', 'slot' => 'HANDS', 'price_coins' => 1, 'modifiers' => []]]);
    }
}
