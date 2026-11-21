<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Admin\Infrastructure\GameRulesetPublisher;
use App\Rewards\Application\CoinLedger;
use App\Rewards\Domain\CoinReason;
use App\Shared\Infrastructure\Config\GameRulesetVersion;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** @phpstan-import-type Snapshot from GameRulesetPublisher */
final class SkiEquipmentTest extends ApiTestCase
{
    public function testUnpublishedSkiDefinitionsFitTheShopEquipmentAndCombatContracts(): void
    {
        $player = $this->openAccount();
        $db = self::getContainer()->get(Connection::class);
        $original = $db->fetchAssociative('SELECT snapshot, version, revision FROM game_ruleset WHERE id = 1');
        self::assertIsArray($original);
        self::assertIsString($original['snapshot']);
        /** @var Snapshot $snapshot */
        $snapshot = json_decode($original['snapshot'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($snapshot['items']);
        $source = file_get_contents(\dirname(__DIR__, 2).'/resources/equipment-drafts/ski.json');
        self::assertIsString($source);
        /** @var list<array{key: string, active: bool, shop: array{available: bool}}> $drafts */
        $drafts = json_decode($source, true, flags: \JSON_THROW_ON_ERROR);
        $contract = [
            'SKI_HELMET' => ['HEAD', 10, 5, 'guardPercent', 3],
            'SKI_JACKET' => ['CHEST', 10, 5, 'guardPercent', 3],
            'SKI_PANTS' => ['LEGS', 15, 7, 'dodgePercent', 3],
            'SKI_POLE' => ['WEAPON', 25, 12, 'damage', 2],
            'SKI_BEANIE' => ['HEAD', 10, 5, 'criticalChancePercent', 2],
        ];
        self::assertEqualsCanonicalizing(array_keys($contract), array_column($drafts, 'key'));
        $staged = $snapshot;
        foreach ($drafts as $draft) {
            self::assertNotContains($draft['key'], array_column($snapshot['items'], 'key'));
            self::assertFalse($draft['active']);
            self::assertFalse($draft['shop']['available']);
            $staged['items'][] = $draft;
            $draft['active'] = true;
            $draft['shop']['available'] = true;
            $snapshot['items'][] = $draft;
        }
        GameRulesetPublisher::validate($staged);
        GameRulesetPublisher::validate($snapshot);
        try {
            $db->executeStatement('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = revision + 1 WHERE id = 1', [json_encode($snapshot, \JSON_THROW_ON_ERROR), GameRulesetVersion::of($snapshot)]);
            self::getContainer()->get(CoinLedger::class)->credit($player->id, CoinReason::WorkoutDrop, Uuid::v7(), 70, new DateTimeImmutable());
            $shop = self::decode($this->get('/api/shop', $player->headers));
            self::assertIsArray($shop['items']);
            $offers = array_column($shop['items'], null, 'key');
            $statistics = self::decode($this->get('/api/inventory', $player->headers))['statistics'];
            self::assertIsArray($statistics);
            self::assertIsArray($statistics['fighter']);
            foreach ($contract as $key => [$slot, $price, $sell, $field, $bonus]) {
                $offer = $offers[$key] ?? null;
                self::assertIsArray($offer);
                self::assertSame(['COMMON', 1, $price], [$offer['rarity'], $offer['minimumLevel'], $offer['priceCoins']]);
                $purchase = $this->post('/api/shop/purchases', ['itemKey' => $key], $player->headers + ['Idempotency-Key' => 'buy-'.$key]);
                self::assertSame(201, $purchase->getStatusCode(), (string) $purchase->getContent());
                $wrong = $this->send('PUT', '/api/inventory/equipment/ACCESSORY', ['itemKey' => $key], $player->headers);
                self::assertSame(422, $wrong->getStatusCode());
                $inventory = self::decode($this->send('PUT', '/api/inventory/equipment/'.$slot, ['itemKey' => $key], $player->headers));
                self::assertResponseIsSuccessful();
                self::assertIsArray($inventory['equipment']);
                $worn = $inventory['equipment'][$slot];
                self::assertIsArray($worn);
                self::assertSame([$key, $sell, 'WEAPON' === $slot ? 'SWORD' : null], [$worn['key'], $worn['sellPriceCoins'], $worn['weaponType']]);
                if ('SKI_BEANIE' === $key) {
                    self::assertIsInt($statistics['fighter']['guardPercent']);
                    $statistics['fighter']['guardPercent'] -= 3;
                }
                self::assertIsInt($statistics['fighter'][$field]);
                $statistics['fighter'][$field] += $bonus;
                self::assertSame($statistics, $inventory['statistics']);
            }
            $battle = self::decode($this->post('/api/battles', [], $player->headers + ['Idempotency-Key' => 'ski-outfit']));
            self::assertResponseStatusCodeSame(201);
            self::assertIsString($battle['id']);
            self::assertIsArray($battle['player']);
            $frozen = $battle['player']['equipment'];
            self::assertIsArray($frozen);
            self::assertEquals(['key' => 'SKI_POLE', 'weaponType' => 'SWORD', 'artworkVersion' => null], $frozen['WEAPON']);
            self::assertEquals(['key' => 'SKI_BEANIE', 'weaponType' => null, 'artworkVersion' => null], $frozen['HEAD']);
            $this->send('PUT', '/api/inventory/equipment/HEAD', ['itemKey' => 'SKI_HELMET'], $player->headers);
            self::assertResponseIsSuccessful();
            self::assertEquals($battle, self::decode($this->get('/api/battles/'.$battle['id'], $player->headers)));
        } finally {
            $db->executeStatement('UPDATE game_ruleset SET snapshot = ?, version = ?, revision = ? WHERE id = 1', [$original['snapshot'], $original['version'], $original['revision']]);
        }
    }
}
