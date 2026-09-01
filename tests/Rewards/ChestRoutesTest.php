<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Domain\CoinReason;
use App\Rewards\Infrastructure\Doctrine\InventoryItemRepository;
use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /api/inventory/chests/{key}/open` (#230) — un coffre est un tirage qu'on ouvre.
 *
 * `WOODEN_CHEST` et `IRON_BOUND_CHEST` sont les deux coffres livrés — voir `items.yaml`.
 * Aucune route ne les grainant, chaque test qui a besoin d'un exemplaire le pose directement
 * par {@see InventoryItemRepository::grant()}, même geste que `credit()` sur `ShopRoutesTest`.
 */
final class ChestRoutesTest extends ApiTestCase
{
    public function testOpeningAnOwnedChestConsumesOneAndReturnsTheOutcome(): void
    {
        $bob = $this->openAccount();
        $this->grantChest($bob->id, 'WOODEN_CHEST', 2);

        $response = $this->openChest($bob, 'WOODEN_CHEST', 'ouverture-1');

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame(['items', 'coins', 'coinsBefore', 'coinsAfter'], array_keys($body));
        self::assertIsArray($body['items']);
        self::assertLessThanOrEqual(1, \count($body['items']), 'Un seul tirage : au plus un objet.');
        self::assertSame(0, $body['coinsBefore']);
        self::assertSame($body['coins'], $body['coinsAfter']);

        // Un exemplaire consommé sur deux : il en reste un.
        $inventory = $this->inventory($bob);
        $wooden = self::itemNamed($inventory['items'], 'WOODEN_CHEST');
        self::assertSame(1, $wooden['quantity']);
    }

    /**
     * **Le contenu ne se révèle jamais avant l'ouverture.** Ni `GET /api/shop` ni
     * `GET /api/inventory` ne rendent la table qui alimente un coffre — un test le prouve
     * plutôt qu'un commentaire le promette : la forme exposée est exactement celle de tout
     * autre objet, rien de plus.
     *
     * **`kind` distingue explicitement un coffre, `slot === null` n'est qu'une conséquence.**
     * L'app décide « Ouvrir » plutôt que « Équiper » sur `kind`, jamais sur l'absence d'une
     * autre valeur — voir le docblock de `DroppedItem`, revu en relecture de la PR du #230.
     */
    public function testTheChestContentIsNeverRevealedBeforeOpening(): void
    {
        $bob = $this->openAccount();
        $this->grantChest($bob->id, 'WOODEN_CHEST');

        $shopEntry = self::itemNamed($this->shop($bob)['items'], 'WOODEN_CHEST');
        self::assertSame(
            ['key', 'kind', 'name', 'rarity', 'slot', 'modifiers', 'priceCoins', 'imageUrl', 'affordable', 'owned', 'minimumLevel', 'unlocked'],
            array_keys($shopEntry),
        );
        self::assertSame('CHEST', $shopEntry['kind']);
        self::assertNull($shopEntry['slot']);
        self::assertSame([], $shopEntry['modifiers']);

        $inventoryEntry = self::itemNamed($this->inventory($bob)['items'], 'WOODEN_CHEST');
        self::assertSame(
            ['key', 'kind', 'name', 'rarity', 'slot', 'modifiers', 'priceCoins', 'imageUrl', 'quantity'],
            array_keys($inventoryEntry),
        );
        self::assertSame('CHEST', $inventoryEntry['kind']);
        self::assertNull($inventoryEntry['slot']);
    }

    /** L'ouverture du dernier exemplaire fait disparaître le coffre du sac — sans supprimer la ligne. */
    public function testOpeningTheLastExemplarRemovesItFromTheBag(): void
    {
        $bob = $this->openAccount();
        $this->grantChest($bob->id, 'WOODEN_CHEST');

        $response = $this->openChest($bob, 'WOODEN_CHEST', 'derniere-ouverture');
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $keys = array_column($this->inventory($bob)['items'], 'key');
        self::assertNotContains('WOODEN_CHEST', $keys, 'Un sac n\'affiche pas ce qu\'il ne contient plus.');

        // La ligne existe toujours, à zéro — ce n'est pas une suppression déguisée, voir le
        // docblock d'`InventoryItem::consumeOne()`.
        $stored = $this->connection()->fetchAssociative(
            "SELECT quantity FROM rewards_inventory_item WHERE user_id = :id AND item_key = 'WOODEN_CHEST'",
            ['id' => $bob->id->toRfc4122()],
        );
        self::assertIsArray($stored);
        self::assertIsNumeric($stored['quantity']);
        self::assertSame(0, (int) $stored['quantity']);
    }

    /**
     * L'audit du tirage (#230) : origine `CHEST`, et `causeId` — `cause_id` en base — pointe
     * la ligne d'inventaire du coffre consommé, exactement comme `sourceId` de la ligne
     * `CHEST` du ledger de pièces.
     */
    public function testOpeningWritesTheAuditTrailAgainstTheConsumedInventoryLine(): void
    {
        $bob = $this->openAccount();
        $this->grantChest($bob->id, 'WOODEN_CHEST');

        $inventoryLineId = $this->connection()->fetchOne(
            "SELECT id FROM rewards_inventory_item WHERE user_id = :id AND item_key = 'WOODEN_CHEST'",
            ['id' => $bob->id->toRfc4122()],
        );

        $response = $this->openChest($bob, 'WOODEN_CHEST', 'audit');
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());

        $roll = $this->connection()->fetchAssociative(
            'SELECT id, origin, table_key, cause_id FROM rewards_loot_roll WHERE user_id = :id',
            ['id' => $bob->id->toRfc4122()],
        );
        self::assertIsArray($roll);
        self::assertSame('CHEST', $roll['origin']);
        self::assertSame('WOODEN_CHEST', $roll['table_key']);
        self::assertSame($inventoryLineId, $roll['cause_id']);

        $body = self::decode($response);

        if ($body['coins'] > 0) {
            $coinRow = $this->connection()->fetchAssociative(
                'SELECT reason, source_id FROM rewards_coin_transaction WHERE user_id = :id',
                ['id' => $bob->id->toRfc4122()],
            );
            self::assertIsArray($coinRow);
            self::assertSame(CoinReason::Chest->value, $coinRow['reason']);
            self::assertSame($roll['id'], $coinRow['source_id'], 'Le `sourceId` de la ligne de pièces est le tirage qui l\'a produit.');
        }
    }

    public function testOpeningAnUnknownKeyIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->openChest($bob, 'GHOST_CHEST', 'coffre-fantome');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-not-owned', self::decode($response)['type']);
    }

    /** Un coffre du catalogue que le joueur ne possède pas — même refus qu'une clé inconnue. */
    public function testOpeningAnUnownedChestIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->openChest($bob, 'WOODEN_CHEST', 'jamais-achete');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-not-owned', self::decode($response)['type']);
    }

    /** Ouvrir le dernier exemplaire vide la ligne — une ouverture de plus est refusée. */
    public function testOpeningAnExhaustedChestIsRefused(): void
    {
        $bob = $this->openAccount();
        $this->grantChest($bob->id, 'WOODEN_CHEST');
        $this->openChest($bob, 'WOODEN_CHEST', 'premiere');

        $response = $this->openChest($bob, 'WOODEN_CHEST', 'seconde');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-not-owned', self::decode($response)['type']);
    }

    /** Un objet possédé qui n'est pas un coffre a son propre refus, distinct d'`item-not-owned`. */
    public function testOpeningAnEquipmentItemIsRefused(): void
    {
        $bob = $this->openAccount();
        $this->grantChest($bob->id, 'IRON_GAUNTLETS');

        $response = $this->openChest($bob, 'IRON_GAUNTLETS', 'pas-un-coffre');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-not-a-chest', self::decode($response)['type']);
    }

    public function testReplayingTheSameIdempotencyKeyDoesNotOpenTwice(): void
    {
        $bob = $this->openAccount();
        $this->grantChest($bob->id, 'WOODEN_CHEST', 2);

        $first = $this->openChest($bob, 'WOODEN_CHEST', 'rejeu');
        $replay = $this->openChest($bob, 'WOODEN_CHEST', 'rejeu');

        self::assertSame('true', $replay->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame($first->getContent(), $replay->getContent());

        $wooden = self::itemNamed($this->inventory($bob)['items'], 'WOODEN_CHEST');
        self::assertSame(1, $wooden['quantity'], 'Un rejeu qui ouvrirait deux fois coûterait un objet au joueur.');
    }

    public function testOpeningWithoutAnIdempotencyKeyIsRefused(): void
    {
        $bob = $this->openAccount();
        $this->grantChest($bob->id, 'WOODEN_CHEST');

        $response = $this->post('/api/inventory/chests/WOODEN_CHEST/open', [], $bob->headers);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAnonymousCallsAreRefused(): void
    {
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->send('POST', '/api/inventory/chests/WOODEN_CHEST/open', [], ['Idempotency-Key' => 'sans-jeton'])->getStatusCode(),
        );
    }

    private function grantChest(Uuid $userId, string $itemKey, int $quantity = 1): void
    {
        $repository = self::getContainer()->get(InventoryItemRepository::class);
        self::assertInstanceOf(InventoryItemRepository::class, $repository);

        for ($i = 0; $i < $quantity; ++$i) {
            $repository->grant($userId, $itemKey, Uuid::v7(), new DateTimeImmutable());
        }
    }

    private function openChest(Account $account, string $key, string $idempotencyKey): Response
    {
        return $this->send(
            'POST',
            \sprintf('/api/inventory/chests/%s/open', $key),
            [],
            $account->headers + ['Idempotency-Key' => $idempotencyKey],
        );
    }

    /**
     * @return array{coins: int, items: list<array<string, mixed>>}
     */
    private function shop(Account $account): array
    {
        $response = $this->get('/api/shop', $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsArray($body['items']);

        /** @var array{coins: int, items: list<array<string, mixed>>} $body */
        return $body;
    }

    /**
     * @return array{coins: int, equipment: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function inventory(Account $account): array
    {
        $response = $this->get('/api/inventory', $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsArray($body['items']);

        /** @var array{coins: int, equipment: array<string, mixed>, items: list<array<string, mixed>>} $body */
        return $body;
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
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

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
