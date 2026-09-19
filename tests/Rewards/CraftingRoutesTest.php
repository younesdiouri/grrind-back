<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\Crafting;
use App\Rewards\Domain\CraftingAudit;
use App\Rewards\Domain\Exception\InsufficientCraftingResources;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Application\GameRulesets;
use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/** @phpstan-import-type Params from \Doctrine\DBAL\DriverManager */
final class CraftingRoutesTest extends ApiTestCase
{
    public function testRecipeShowsStockThenCraftsAndReplaysOnlyOnce(): void
    {
        $bob = $this->openAccount();
        $inventory = self::getContainer()->get(InventoryItemRepository::class);
        $inventory->grantQuantity($bob->id, 'NAFS_ESSENCE', 12, new DateTimeImmutable());
        $response = $this->get('/api/crafting/recipes', $bob->headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $list = self::decode($response);
        self::assertIsArray($list['recipes']);
        self::assertIsArray($list['recipes'][0]);
        self::assertTrue($list['recipes'][0]['canCraft']);
        $headers = $bob->headers + ['Idempotency-Key' => 'craft-once'];
        $response = $this->post('/api/crafting', ['recipeKey' => 'IRON_GAUNTLETS'], $headers);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $body = self::decode($response);
        self::assertIsArray($body['result']);
        self::assertSame(1, $body['result']['ownedQuantity']);
        self::assertIsArray($body['costs']);
        self::assertIsArray($body['costs'][0]);
        self::assertSame(2, $body['costs'][0]['ownedQuantity']);
        $replay = $this->post('/api/crafting', ['recipeKey' => 'IRON_GAUNTLETS'], $headers);
        self::assertSame($response->getContent(), $replay->getContent());
        self::assertSame('true', $replay->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(CraftingAudit::class)->count(['userId' => $bob->id]));
        self::assertSame(2, self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, 'NAFS_ESSENCE')?->quantity());
    }

    public function testLostHttpReceiptCanBeRecoveredAfterReservationIsGone(): void
    {
        $bob = $this->openAccount();
        self::getContainer()->get(InventoryItemRepository::class)->grantQuantity($bob->id, 'NAFS_ESSENCE', 20, new DateTimeImmutable());
        $headers = $bob->headers + ['Idempotency-Key' => 'lost-http-receipt'];
        $first = $this->post('/api/crafting', ['recipeKey' => 'IRON_GAUNTLETS'], $headers);
        self::assertSame(201, $first->getStatusCode());
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $manager->getConnection()->executeStatement('DELETE FROM shared_idempotency_key WHERE user_id = ?', [$bob->id->toRfc4122()]);
        $recovered = $this->post('/api/crafting', ['recipeKey' => 'IRON_GAUNTLETS'], $headers);
        self::assertSame(201, $recovered->getStatusCode(), (string) $recovered->getContent());
        self::assertSame($first->getContent(), $recovered->getContent());
        self::assertSame(10, self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, 'NAFS_ESSENCE')?->quantity());
        self::assertSame(1, self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, 'IRON_GAUNTLETS')?->quantity());
    }

    public function testInsufficientStockAndUnknownRecipeDoNotProduceEquipment(): void
    {
        $bob = $this->openAccount();
        foreach (['IRON_GAUNTLETS' => 'insufficient-crafting-resources', 'UNKNOWN' => 'recipe-unavailable'] as $recipe => $error) {
            $response = $this->post('/api/crafting', ['recipeKey' => $recipe], $bob->headers + ['Idempotency-Key' => $recipe]);
            self::assertSame(422, $response->getStatusCode());
            self::assertSame('https://grrind.app/problems/'.$error, self::decode($response)['type']);
        }
        self::assertNull(self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, 'IRON_GAUNTLETS'));
    }

    public function testSecondResourceFailureRollsBackFirstConsumption(): void
    {
        $bob = $this->openAccount();
        $inventory = self::getContainer()->get(InventoryItemRepository::class);
        $inventory->grantQuantity($bob->id, 'NAFS_ESSENCE', 10, new DateTimeImmutable());
        $snapshot = self::getContainer()->get(GameRulesets::class)->snapshot();
        self::assertIsArray($snapshot['items']);
        $snapshot['items'][] = ['key' => 'OTHER_DUST', 'kind' => 'RESOURCE', 'rarity' => 'COMMON', 'price_coins' => 0, 'modifiers' => []];
        $snapshot['recipes'] = [['key' => 'IRON_GAUNTLETS', 'result_item' => 'IRON_GAUNTLETS', 'quantity' => 1, 'costs' => ['NAFS_ESSENCE' => 10, 'OTHER_DUST' => 1]]];
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $crafting = new Crafting(new FrozenGameRulesets($snapshot, 'frozen'), $inventory, self::getContainer()->get(ItemTranslator::class), $manager, new MockClock());
        try {
            $crafting->craft($bob->id, 'IRON_GAUNTLETS');
            self::fail('La seconde ressource manque.');
        } catch (InsufficientCraftingResources) {
            self::assertSame(10, $manager->getConnection()->fetchOne('SELECT quantity FROM rewards_inventory_item WHERE user_id = ? AND item_key = ?', [$bob->id->toRfc4122(), 'NAFS_ESSENCE']));
            self::assertSame(0, $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM rewards_crafting_audit'));
        }
    }

    public function testAuditFailureRollsBackConsumptionAndProduction(): void
    {
        $bob = $this->openAccount();
        $inventory = self::getContainer()->get(InventoryItemRepository::class);
        $inventory->grantQuantity($bob->id, 'NAFS_ESSENCE', 10, new DateTimeImmutable());
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $failingAudit = $this->createStub(EntityManagerInterface::class);
        $failingAudit->method('persist')->willThrowException(new RuntimeException('Audit unavailable'));
        $crafting = new Crafting(self::getContainer()->get(GameRulesets::class), $inventory, self::getContainer()->get(ItemTranslator::class), $failingAudit, new MockClock());
        try {
            $crafting->craft($bob->id, 'IRON_GAUNTLETS');
            self::fail('L’audit devait échouer.');
        } catch (RuntimeException $error) {
            self::assertSame('Audit unavailable', $error->getMessage());
        }
        self::assertSame(10, $manager->getConnection()->fetchOne('SELECT quantity FROM rewards_inventory_item WHERE user_id = ? AND item_key = ?', [$bob->id->toRfc4122(), 'NAFS_ESSENCE']));
        self::assertSame(0, $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM rewards_inventory_item WHERE user_id = ? AND item_key = ?', [$bob->id->toRfc4122(), 'IRON_GAUNTLETS']));
        self::assertSame(0, $manager->getConnection()->fetchOne('SELECT COUNT(*) FROM rewards_crafting_audit'));
    }

    public function testCraftingKeepsPlayerInventoryLockedUntilTheOuterCommit(): void
    {
        $bob = $this->openAccount();
        $inventory = self::getContainer()->get(InventoryItemRepository::class);
        $inventory->grantQuantity($bob->id, 'NAFS_ESSENCE', 10, new DateTimeImmutable());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        /** @var Params $params */
        $params = $em->getConnection()->getParams();
        $other = \Doctrine\DBAL\DriverManager::getConnection($params);
        $em->beginTransaction();
        try {
            self::getContainer()->get(Crafting::class)->craft($bob->id, 'IRON_GAUNTLETS', 'parallel-craft');
            self::assertFalse($other->fetchOne('SELECT pg_try_advisory_xact_lock(hashtext(?))', [$bob->id->toRfc4122().':inventory']));
        } finally {
            $em->rollback();
            $other->close();
        }
    }

    public function testAuthenticationAndIdempotencyAreRequired(): void
    {
        self::assertSame(401, $this->get('/api/crafting/recipes')->getStatusCode());
        self::assertSame(401, $this->post('/api/crafting', ['recipeKey' => 'IRON_GAUNTLETS'])->getStatusCode());
        $bob = $this->openAccount();
        self::assertSame(400, $this->post('/api/crafting', ['recipeKey' => 'IRON_GAUNTLETS'], $bob->headers)->getStatusCode());
    }

    public function testResourceCannotBeSoldOrEquipped(): void
    {
        $bob = $this->openAccount();
        self::getContainer()->get(InventoryItemRepository::class)->grantQuantity($bob->id, 'NAFS_ESSENCE', 3, new DateTimeImmutable());
        self::assertSame(422, $this->post('/api/inventory/sales', ['itemKey' => 'NAFS_ESSENCE', 'expectedSellPriceCoins' => 0], $bob->headers + ['Idempotency-Key' => 'resource-sale'])->getStatusCode());
        self::assertSame(422, $this->send('PUT', '/api/inventory/equipment/HANDS', ['itemKey' => 'NAFS_ESSENCE'], $bob->headers)->getStatusCode());
        self::assertSame(3, self::getContainer()->get(InventoryItemRepository::class)->ofPlayerAndItem($bob->id, 'NAFS_ESSENCE')?->quantity());
    }
}
