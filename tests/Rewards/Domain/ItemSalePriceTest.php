<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\ItemCatalog;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ItemSalePriceTest extends TestCase
{
    public function testLegacySnapshotInitializesHalfTheOddPurchasePrice(): void
    {
        $catalog = new ItemCatalog([['key' => 'BOOTS', 'rarity' => 'COMMON', 'slot' => 'FEET', 'price_coins' => 31, 'modifiers' => []]]);
        self::assertSame(15, $catalog->find('BOOTS')?->sellPriceCoins);
    }

    public function testExplicitZeroDoesNotFollowThePurchasePrice(): void
    {
        $catalog = new ItemCatalog([['key' => 'BOOTS', 'rarity' => 'COMMON', 'slot' => 'FEET', 'price_coins' => 900, 'sell_price_coins' => 0, 'modifiers' => []]]);
        self::assertSame(0, $catalog->find('BOOTS')?->sellPriceCoins);
    }

    public function testSalePriceParticipatesInTheRulesetFingerprint(): void
    {
        $snapshot = ['items' => [['key' => 'BOOTS', 'price_coins' => 30, 'sell_price_coins' => 15]]];
        $version = GameRulesetVersion::of($snapshot);
        $snapshot['items'][0]['sell_price_coins'] = 7;
        self::assertNotSame($version, GameRulesetVersion::of($snapshot));
    }

    public function testNegativeSalePriceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ItemCatalog([['key' => 'BOOTS', 'rarity' => 'COMMON', 'slot' => 'FEET', 'price_coins' => 30, 'sell_price_coins' => -1, 'modifiers' => []]]);
    }
}
