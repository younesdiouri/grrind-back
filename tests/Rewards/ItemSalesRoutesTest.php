<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\Inventory;
use App\Rewards\Domain\CoinReason;
use App\Rewards\Infrastructure\Doctrine\CoinTransactionRepository;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class ItemSalesRoutesTest extends ApiTestCase
{
    public function testSaleAndReplayCreditOnlyOnceAndKeepTheEmptyLine(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob, 'WORN_RUNNING_SHOES');
        $inventory = self::decode($this->get('/api/inventory', $bob->headers));
        self::assertIsArray($inventory['items']);
        self::assertIsArray($inventory['items'][0]);
        self::assertSame(15, $inventory['items'][0]['sellPriceCoins']);

        $response = $this->sell($bob, 'WORN_RUNNING_SHOES', 15, 'sale-one');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['itemKey' => 'WORN_RUNNING_SHOES', 'quantity' => 0, 'coins' => 15, 'coinsBefore' => 0, 'coinsAfter' => 15], self::decode($response));
        $replay = $this->sell($bob, 'WORN_RUNNING_SHOES', 15, 'sale-one');
        self::assertSame($response->getContent(), $replay->getContent());
        self::assertSame('true', $replay->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame(0, self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, 'WORN_RUNNING_SHOES')?->quantity());
        self::assertSame(1, self::getContainer()->get(CoinTransactionRepository::class)->count(['userId' => $bob->id, 'reason' => CoinReason::Sale]));
        $empty = $this->sell($bob);
        self::assertSame(422, $empty->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-not-owned', self::decode($empty)['type']);
    }

    public function testEquippedDuplicateCanBeSoldButLastEquippedUnitCannot(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob, 'WORN_RUNNING_SHOES');
        $this->grant($bob, 'WORN_RUNNING_SHOES');
        $this->send('PUT', '/api/inventory/equipment/FEET', ['itemKey' => 'WORN_RUNNING_SHOES'], $bob->headers);
        self::assertSame(200, $this->sell($bob)->getStatusCode());
        $refused = $this->sell($bob);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-equipped', self::decode($refused)['type']);
        $line = self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, 'WORN_RUNNING_SHOES');
        self::assertSame(1, $line?->quantity());
        self::assertSame('FEET', $line?->slot()?->value);
    }

    public function testDistinctSalesAndRepurchaseAreAllowed(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob, 'WORN_RUNNING_SHOES');
        $this->grant($bob, 'WORN_RUNNING_SHOES');
        self::assertSame(200, $this->sell($bob)->getStatusCode());
        self::assertSame(200, $this->sell($bob)->getStatusCode());
        $purchase = $this->post('/api/shop/purchases', ['itemKey' => 'WORN_RUNNING_SHOES'], $bob->headers + ['Idempotency-Key' => 'repurchase']);
        self::assertSame(201, $purchase->getStatusCode(), (string) $purchase->getContent());
        self::assertSame(200, $this->sell($bob)->getStatusCode());
        $lines = self::getContainer()->get(CoinTransactionRepository::class)->findBy(['userId' => $bob->id, 'reason' => CoinReason::Sale]);
        self::assertCount(3, $lines);
        self::assertNotEquals($lines[0]->sourceId(), $lines[1]->sourceId());
    }

    #[DataProvider('refusals')]
    public function testBusinessRefusalDoesNotConsumeOrCredit(string $key, bool $owned, int $price, string $type): void
    {
        $bob = $this->openAccount();
        if ($owned) {
            $this->grant($bob, $key);
        }
        $response = $this->sell($bob, $key, $price);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/'.$type, self::decode($response)['type']);
        self::assertSame(0, self::getContainer()->get(CoinTransactionRepository::class)->balanceOf($bob->id));
        if ($owned) {
            self::assertSame(1, self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, $key)?->quantity());
        }
    }

    /** @return iterable<string, array{string, bool, int, string}> */
    public static function refusals(): iterable
    {
        yield 'unknown' => ['UNKNOWN', false, 0, 'item-not-owned'];
        yield 'not owned' => ['WORN_RUNNING_SHOES', false, 15, 'item-not-owned'];
        yield 'chest' => ['WOODEN_CHEST', true, 25, 'item-not-sellable'];
        yield 'price changed' => ['WORN_RUNNING_SHOES', true, 99, 'sale-price-changed'];
    }

    public function testAuthenticationAndIdempotencyKeyAreRequired(): void
    {
        self::assertSame(401, $this->post('/api/inventory/sales', ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15])->getStatusCode());
        $bob = $this->openAccount();
        self::assertSame(400, $this->post('/api/inventory/sales', ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15], $bob->headers)->getStatusCode());
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRejected(array $payload): void
    {
        $bob = $this->openAccount();
        self::assertSame(422, $this->post('/api/inventory/sales', $payload, $bob->headers + ['Idempotency-Key' => Uuid::v7()->toRfc4122()])->getStatusCode());
    }

    /** @return iterable<array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        yield [['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => -1]];
        yield [['itemKey' => '', 'expectedSellPriceCoins' => 15]];
        yield [['itemKey' => 'WORN_RUNNING_SHOES']];
        yield [['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 'not a number']];
    }

    private function grant(Account $account, string $key): void
    {
        self::getContainer()->get(Inventory::class)->grant($account->id, $key, Uuid::v7(), new DateTimeImmutable());
    }

    private function sell(Account $account, string $key = 'WORN_RUNNING_SHOES', int $price = 15, ?string $idempotency = null): Response
    {
        return $this->post('/api/inventory/sales', ['itemKey' => $key, 'expectedSellPriceCoins' => $price], $account->headers + ['Idempotency-Key' => $idempotency ?? Uuid::v7()->toRfc4122()]);
    }
}
