<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\CoinLedger;
use App\Rewards\Application\Inventory;
use App\Rewards\Application\SellItem;
use App\Rewards\Application\SellItemHandler;
use App\Rewards\Domain\ItemCatalog;
use App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ItemSaleTransactionTest extends ApiTestCase
{
    public function testZeroPriceConsumesWithoutWritingAnInvalidLedgerEntry(): void
    {
        $bob = $this->openAccount();
        self::getContainer()->get(Inventory::class)->grant($bob->id, 'BOOTS', Uuid::v7(), new DateTimeImmutable());
        $handler = new SellItemHandler($this->catalog(0), self::getContainer()->get(InventoryItemRepository::class), self::getContainer()->get(CoinLedger::class), new MockClock());
        $receipt = $handler(new SellItem($bob->id, 'BOOTS', 0));
        self::assertSame(0, $receipt->quantity);
        self::assertSame(0, $receipt->coins);
        self::assertSame($receipt->coinsBefore, $receipt->coinsAfter);
        self::assertSame(0, self::getContainer()->get(CoinTransactionRepository::class)->count(['userId' => $bob->id]));
    }

    public function testCreditFailureRollsBackTheInventoryDecrement(): void
    {
        $bob = $this->openAccount();
        self::getContainer()->get(Inventory::class)->grant($bob->id, 'BOOTS', Uuid::v7(), new DateTimeImmutable());
        $transactions = $this->createStub(CoinTransactionRepository::class);
        $transactions->method('lockedBalanceOf')->willReturn(0);
        $transactions->method('record')->willThrowException(new RuntimeException('Ledger unavailable'));
        $handler = new SellItemHandler($this->catalog(7), self::getContainer()->get(InventoryItemRepository::class), new CoinLedger($transactions), new MockClock());
        try {
            $handler(new SellItem($bob->id, 'BOOTS', 7));
            self::fail('Le crédit devait échouer.');
        } catch (RuntimeException $error) {
            self::assertSame('Ledger unavailable', $error->getMessage());
        }
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(1, $connection->fetchOne('SELECT quantity FROM rewards_inventory_item WHERE user_id = ?', [$bob->id->toRfc4122()]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM rewards_coin_transaction WHERE user_id = ?', [$bob->id->toRfc4122()]));
    }

    private function catalog(int $price): ItemCatalog
    {
        return new ItemCatalog([['key' => 'BOOTS', 'rarity' => 'COMMON', 'slot' => 'FEET', 'price_coins' => 30, 'sell_price_coins' => $price, 'modifiers' => []]]);
    }
}
