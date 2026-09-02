<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\CoinLedger;
use App\Rewards\Domain\CoinReason;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/inventory`, `PUT`/`DELETE /api/inventory/equipment/{slot}` (#30) — le sac, la
 * doublure équipée, la bourse, et les deux gestes qui font vivre l'équipement. Les objets
 * sont crédités directement par {@see InventoryItemRepository::grant()} plutôt que par un
 * vrai tirage : ce qui se prouve ici est le contrat HTTP, pas le hasard du loot, déjà
 * couvert par `BattleLootTest` et `ImportLootTest`.
 *
 * @phpstan-type InventoryLine array{key: string, kind: string, name: string, rarity: string, slot: string|null, modifiers: list<array<string, mixed>>, priceCoins: int, quantity: int}
 * @phpstan-type InventoryBody array{coins: int, equipment: array<string, InventoryLine|null>, items: list<InventoryLine>}
 */
final class InventoryRoutesTest extends ApiTestCase
{
    public function testANewAccountHasAnEmptySackAndAnEmptyPurse(): void
    {
        $bob = $this->openAccount();

        $body = $this->inventory($bob);

        self::assertSame(0, $body['coins']);
        self::assertSame([], $body['items']);
        self::assertSame(
            ['HEAD' => null, 'CHEST' => null, 'HANDS' => null, 'LEGS' => null, 'FEET' => null, 'ACCESSORY' => null, 'WEAPON' => null],
            $body['equipment'],
            'Les sept emplacements sont toujours présents, vides ou non.',
        );
    }

    /** L'ordre des clés de l'enveloppe est du contrat versionné. */
    public function testTheKeyOrderOfTheEnvelopeIsFixed(): void
    {
        $bob = $this->openAccount();

        self::assertSame(['coins', 'equipment', 'items'], array_keys($this->inventory($bob)));
    }

    public function testAHotInventoryReadDoesNotQueryThePublishedRulesetAgain(): void
    {
        $bob = $this->openAccount('inventory-hot@grrind.app');
        $this->client->disableReboot();
        $this->inventory($bob);

        $this->client->enableProfiler();
        $this->inventory($bob);
        $this->assertNoRulesetSql();
    }

    public function testAnOwnedItemCarriesEverythingNeededToDisplayItWithoutAFurtherRequest(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob->id, 'WORN_RUNNING_SHOES');

        $items = $this->inventory($bob)['items'];
        self::assertCount(1, $items);
        $item = $items[0];

        self::assertSame(
            ['key', 'kind', 'name', 'rarity', 'slot', 'modifiers', 'priceCoins', 'imageUrl', 'quantity'],
            array_keys($item),
        );
        self::assertSame('WORN_RUNNING_SHOES', $item['key']);
        self::assertSame(self::translator()->nameOf('WORN_RUNNING_SHOES'), $item['name']);
        self::assertSame('COMMON', $item['rarity']);
        self::assertSame('FEET', $item['slot']);
        self::assertSame(30, $item['priceCoins']);
        self::assertSame(1, $item['quantity']);
        self::assertNotEmpty($item['modifiers']);
    }

    public function testATrustedFlyProxyMakesItemUrlsUseTheOriginalHttpsScheme(): void
    {
        $bob = $this->openAccount('https-proxy@grrind.app');
        $this->grant($bob->id, 'WORN_RUNNING_SHOES');

        $this->client->request('GET', '/api/inventory', [], [], [
            'HTTP_AUTHORIZATION' => $bob->headers['Authorization'],
            'HTTP_HOST' => 'grrind-back.fly.dev',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $body = self::decode($this->client->getResponse());
        $items = $body['items'] ?? null;
        self::assertIsArray($items);
        $item = $items[0] ?? null;
        self::assertIsArray($item);
        $imageUrl = $item['imageUrl'] ?? null;
        self::assertIsString($imageUrl);
        self::assertStringStartsWith('https://grrind-back.fly.dev/game-images/', $imageUrl);
    }

    public function testGrantingTheSameItemTwiceIsReflectedAsAQuantity(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob->id, 'WORN_RUNNING_SHOES');
        $this->grant($bob->id, 'WORN_RUNNING_SHOES');

        $items = $this->inventory($bob)['items'];
        self::assertCount(1, $items, 'Deux tirages du même objet fusionnent en une ligne.');
        self::assertSame(2, $items[0]['quantity']);
    }

    public function testThePurseBalanceIsReturnedAlongsideTheSack(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 42);

        self::assertSame(42, $this->inventory($bob)['coins']);
    }

    public function testEquippingAnOwnedCompatibleItemPlacesItInTheEquipmentMap(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob->id, 'IRON_GAUNTLETS');

        $body = $this->equip($bob, 'HANDS', 'IRON_GAUNTLETS');

        $hands = $body['equipment']['HANDS'];
        self::assertNotNull($hands);
        self::assertSame('IRON_GAUNTLETS', $hands['key']);
        self::assertNull($body['equipment']['HEAD']);
    }

    /** `PUT` rend le même état, jamais une erreur, sur un réequipement identique. */
    public function testEquippingIsIdempotent(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob->id, 'IRON_GAUNTLETS');

        $this->equip($bob, 'HANDS', 'IRON_GAUNTLETS');
        $second = $this->equip($bob, 'HANDS', 'IRON_GAUNTLETS');

        $hands = $second['equipment']['HANDS'];
        self::assertNotNull($hands);
        self::assertSame('IRON_GAUNTLETS', $hands['key']);
    }

    /**
     * Le cœur du ticket : `PUT` sur un emplacement occupé par un **autre** objet échange
     * plutôt que de refuser, et l'ancien occupant reste possédé — voir le docblock
     * d'`InventoryItemRepository::equip()`. `WORN_RUNNING_SHOES` et `STORMCALLERS_BOOTS`
     * se portent tous deux en `FEET`.
     */
    public function testEquippingIntoAnOccupiedSlotSwapsTheOccupantBackToTheBag(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob->id, 'WORN_RUNNING_SHOES');
        $this->grant($bob->id, 'STORMCALLERS_BOOTS');

        $this->equip($bob, 'FEET', 'WORN_RUNNING_SHOES');
        $body = $this->equip($bob, 'FEET', 'STORMCALLERS_BOOTS');

        $feet = $body['equipment']['FEET'];
        self::assertNotNull($feet);
        self::assertSame('STORMCALLERS_BOOTS', $feet['key']);

        $shoes = self::itemNamed($body['items'], 'WORN_RUNNING_SHOES');
        self::assertSame(1, $shoes['quantity'], 'Toujours possédées, simplement plus équipées nulle part.');
    }

    public function testEquippingAnUnknownSlotIsRefused(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob->id, 'IRON_GAUNTLETS');

        $response = $this->send('PUT', '/api/inventory/equipment/TAIL', ['itemKey' => 'IRON_GAUNTLETS'], $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/equipment-slot-unknown', self::decode($response)['type']);
    }

    public function testEquippingAnItemNotOwnedIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->send('PUT', '/api/inventory/equipment/HANDS', ['itemKey' => 'IRON_GAUNTLETS'], $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-not-owned', self::decode($response)['type']);
    }

    public function testEquippingIntoAnIncompatibleSlotIsRefused(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob->id, 'IRON_GAUNTLETS');

        $response = $this->send('PUT', '/api/inventory/equipment/HEAD', ['itemKey' => 'IRON_GAUNTLETS'], $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/equipment-slot-incompatible', self::decode($response)['type']);
    }

    public function testUnequippingClearsTheSlotButKeepsTheItem(): void
    {
        $bob = $this->openAccount();
        $this->grant($bob->id, 'IRON_GAUNTLETS');
        $this->equip($bob, 'HANDS', 'IRON_GAUNTLETS');

        $body = $this->unequip($bob, 'HANDS');

        self::assertNull($body['equipment']['HANDS']);
        self::assertSame(1, self::itemNamed($body['items'], 'IRON_GAUNTLETS')['quantity']);
    }

    /** Vider un emplacement déjà vide n'est pas une erreur — même geste qu'un `DELETE` rejoué. */
    public function testUnequippingAnAlreadyEmptySlotIsIdempotent(): void
    {
        $bob = $this->openAccount();

        self::assertNull($this->unequip($bob, 'HANDS')['equipment']['HANDS']);
    }

    public function testUnequippingAnUnknownSlotIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->send('DELETE', '/api/inventory/equipment/TAIL', null, $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/equipment-slot-unknown', self::decode($response)['type']);
    }

    public function testAnAccountNeverSeesAnothersInventory(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();
        $this->grant($alice->id, 'IRON_GAUNTLETS');

        self::assertSame([], $this->inventory($bob)['items']);
    }

    public function testAnonymousCallsAreRefused(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/inventory')->getStatusCode());
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->send('PUT', '/api/inventory/equipment/HANDS', ['itemKey' => 'IRON_GAUNTLETS'])->getStatusCode(),
        );
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->send('DELETE', '/api/inventory/equipment/HANDS')->getStatusCode());
    }

    /**
     * @return InventoryBody
     */
    private function inventory(Account $account): array
    {
        $response = $this->get('/api/inventory', $account->headers);

        return self::inventoryBody($response);
    }

    /**
     * @return InventoryBody
     */
    private function equip(Account $account, string $slot, string $itemKey): array
    {
        $response = $this->send('PUT', '/api/inventory/equipment/'.$slot, ['itemKey' => $itemKey], $account->headers);

        return self::inventoryBody($response);
    }

    /**
     * @return InventoryBody
     */
    private function unequip(Account $account, string $slot): array
    {
        $response = $this->send('DELETE', '/api/inventory/equipment/'.$slot, null, $account->headers);

        return self::inventoryBody($response);
    }

    /**
     * @return InventoryBody
     */
    private static function inventoryBody(Response $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsInt($body['coins']);
        self::assertIsArray($body['equipment']);
        self::assertIsArray($body['items']);

        /** @var InventoryBody $body */
        return $body;
    }

    private function grant(Uuid $userId, string $itemKey): void
    {
        self::repository()->grant($userId, $itemKey, Uuid::v7(), new DateTimeImmutable());
    }

    private function credit(Uuid $userId, int $amount): void
    {
        $ledger = self::getContainer()->get(CoinLedger::class);
        self::assertInstanceOf(CoinLedger::class, $ledger);

        $ledger->credit($userId, CoinReason::WorkoutDrop, Uuid::v7(), $amount, new DateTimeImmutable());
    }

    private static function repository(): InventoryItemRepository
    {
        $repository = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $repository);

        return $repository;
    }

    private static function translator(): ItemTranslator
    {
        $translator = self::getContainer()->get(ItemTranslator::class);
        self::assertInstanceOf(ItemTranslator::class, $translator);

        return $translator;
    }

    /**
     * @param list<InventoryLine> $items
     *
     * @return InventoryLine
     */
    private static function itemNamed(array $items, string $key): array
    {
        foreach ($items as $item) {
            if ($key === $item['key']) {
                return $item;
            }
        }

        self::fail(\sprintf('Aucun objet "%s" dans la liste.', $key));
    }
}
