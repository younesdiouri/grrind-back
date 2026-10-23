<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\CoinLedger;
use App\Rewards\Domain\CoinReason;
use App\Rewards\Domain\LootRoller;
use App\Rewards\Domain\LootTables;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

final class UnityEquipmentTest extends ApiTestCase
{
    public function testUnityEquipmentIsAffordableAtLevelOneAndOnlyGivesCombatEffects(): void
    {
        $player = $this->openAccount();
        $ledger = self::getContainer()->get(CoinLedger::class);
        $ledger->credit($player->id, CoinReason::WorkoutDrop, Uuid::v7(), 25, new DateTimeImmutable());
        $shop = self::decode($this->get('/api/shop', $player->headers));
        self::assertIsArray($shop['items']);
        $items = array_column($shop['items'], null, 'key');
        foreach (self::equipment() as $key => [$slot, $price, $type, $value]) {
            self::assertArrayHasKey($key, $items);
            $item = $items[$key];
            self::assertIsArray($item);
            self::assertSame($slot, $item['slot']);
            self::assertSame('COMMON', $item['rarity']);
            self::assertSame($price, $item['priceCoins']);
            self::assertSame(1, $item['minimumLevel']);
            self::assertTrue($item['unlocked']);
            self::assertTrue($item['affordable']);
            self::assertSame([['type' => $type, 'value' => $value, 'discipline' => null]], $item['modifiers']);
        }
    }

    public function testEveryVariantCanBePurchasedEquippedRemovedAndSoldWithItsServerBonus(): void
    {
        $player = $this->openAccount();
        self::getContainer()->get(CoinLedger::class)->credit($player->id, CoinReason::WorkoutDrop, Uuid::v7(), 175, new DateTimeImmutable());
        $baseline = $this->inventory($player)['statistics'];
        self::assertIsArray($baseline);
        self::assertIsArray($baseline['fighter']);
        $balance = 175;
        foreach (self::equipment() as $key => [$slot, $price, $type, $value]) {
            $path = '/api/inventory/equipment/'.$slot;
            $refused = $this->send('PUT', $path, ['itemKey' => $key], $player->headers);
            self::assertSame(422, $refused->getStatusCode());
            self::assertSame('https://grrind.app/problems/item-not-owned', self::decode($refused)['type']);
            $headers = $player->headers + ['Idempotency-Key' => 'buy-'.$key];
            $purchased = $this->post('/api/shop/purchases', ['itemKey' => $key], $headers);
            self::assertSame(201, $purchased->getStatusCode(), (string) $purchased->getContent());
            self::assertSame($balance -= $price, self::decode($purchased)['coinsAfter']);
            self::assertSame($purchased->getContent(), $this->post('/api/shop/purchases', ['itemKey' => $key], $headers)->getContent());
            $wrongSlot = $this->send('PUT', '/api/inventory/equipment/HEAD', ['itemKey' => $key], $player->headers);
            self::assertSame(422, $wrongSlot->getStatusCode());
            self::assertSame('https://grrind.app/problems/equipment-slot-incompatible', self::decode($wrongSlot)['type']);
            $equipped = self::decode($this->send('PUT', $path, ['itemKey' => $key], $player->headers));
            self::assertResponseIsSuccessful();
            self::assertIsArray($equipped['statistics']);
            self::assertSame($baseline['attributes'], $equipped['statistics']['attributes']);
            $field = match ($type) {
                'CRITICAL_CHANCE_BONUS' => 'criticalChancePercent',
                'DODGE_BONUS' => 'dodgePercent',
                'GUARD_BONUS' => 'guardPercent',
                default => 'damage',
            };
            $expected = $baseline;
            self::assertIsInt($expected['fighter'][$field]);
            $expected['fighter'][$field] += 'damage' === $field ? $value : intdiv($value, 10);
            self::assertSame($expected, $equipped['statistics']);
            self::assertIsArray($equipped['equipment']);
            self::assertIsArray($equipped['equipment'][$slot]);
            self::assertSame($key, $equipped['equipment'][$slot]['key']);
            self::assertSame(intdiv($price, 2), $equipped['equipment'][$slot]['sellPriceCoins']);
            $profile = self::decode($this->get('/api/players/'.$player->id->toRfc4122(), $player->headers));
            self::assertSame($equipped['statistics'], $profile['statistics']);
            self::assertIsArray($profile['inventory']);
            self::assertIsArray($profile['inventory']['equipment']);
            self::assertIsArray($profile['inventory']['equipment'][$slot]);
            self::assertSame($key, $profile['inventory']['equipment'][$slot]['key']);
            $imageUrl = $equipped['equipment'][$slot]['imageUrl'];
            self::assertIsString($imageUrl);
            $imagePath = parse_url($imageUrl, \PHP_URL_PATH);
            self::assertIsString($imagePath);
            $this->get($imagePath);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('content-type', 'image/png');
            $removed = self::decode($this->send('DELETE', $path, null, $player->headers));
            self::assertSame($baseline, $removed['statistics']);
            $saleHeaders = $player->headers + ['Idempotency-Key' => 'sell-'.$key];
            $payload = ['itemKey' => $key, 'expectedSellPriceCoins' => intdiv($price, 2)];
            $sale = $this->post('/api/inventory/sales', $payload, $saleHeaders);
            self::assertSame(200, $sale->getStatusCode(), (string) $sale->getContent());
            self::assertSame($balance += intdiv($price, 2), self::decode($sale)['coinsAfter']);
            self::assertSame($sale->getContent(), $this->post('/api/inventory/sales', $payload, $saleHeaders)->getContent());
        }
        self::assertSame($balance, $this->inventory($player)['coins']);
        self::assertSame([], $this->inventory($player)['items']);
    }

    public function testWoodenChestWeightsCoverEveryRollAndAllNewEquipment(): void
    {
        $tables = self::getContainer()->get(LootTables::class);
        $roller = self::getContainer()->get(LootRoller::class);
        $table = $tables->forChest('WOODEN_CHEST');
        self::assertNotNull($table);
        self::assertSame(135, array_sum(array_map(static fn ($entry): int => $entry->weight, $table->entries)));
        self::assertSame(10, $table->coins->minimum);
        self::assertSame(25, $table->coins->maximum);
        $random = new Randomizer(new Xoshiro256StarStar(313));
        $rolls = [];
        for ($i = 0; $i < 5000 && \count($rolls) < 135; ++$i) {
            $outcome = $roller->rollForChest($tables, 'WOODEN_CHEST', [], $random);
            self::assertNotNull($outcome);
            $rolls[$outcome->itemRoll] = $outcome->items[0] ?? 'NONE';
        }
        self::assertCount(135, $rolls, 'La graine fixe explore chaque résultat du vrai moteur.');
        $counts = array_count_values($rolls);
        self::assertSame(55, $counts['NONE']);
        self::assertSame(25, $counts['WORN_RUNNING_SHOES']);
        self::assertSame(20, $counts['IRON_GAUNTLETS']);
        foreach (array_keys(self::equipment()) as $key) {
            self::assertSame(5, $counts[$key]);
        }
    }

    /** @return array<mixed> */
    private function inventory(Account $player): array
    {
        return self::decode($this->get('/api/inventory', $player->headers));
    }

    /** @return array<string, array{string, int, string, int}> */
    private static function equipment(): array
    {
        return [
            'GOLDEN_BOXING_GLOVES' => ['HANDS', 20, 'CRITICAL_CHANCE_BONUS', 30],
            'LES_GUINZZ_PURPLE' => ['LEGS', 15, 'DODGE_BONUS', 30],
            'LES_GUINZZ_CYAN' => ['LEGS', 15, 'DODGE_BONUS', 30],
            'LES_GUINZZ_CORAL' => ['LEGS', 15, 'DODGE_BONUS', 30],
            'SPORT_TSHIRT_SLATE' => ['CHEST', 10, 'GUARD_BONUS', 30],
            'SPORT_TSHIRT_CORAL' => ['CHEST', 10, 'GUARD_BONUS', 30],
            'LE_POIDS_LOURD' => ['WEAPON', 25, 'DAMAGE_BONUS', 2],
        ];
    }
}
