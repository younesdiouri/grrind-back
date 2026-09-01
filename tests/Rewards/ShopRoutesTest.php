<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Progression\Domain\LevelCurve;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Rewards\Application\CoinLedger;
use App\Rewards\Domain\CoinReason;
use App\Rewards\Infrastructure\Translation\ItemTranslator;
use App\Shared\Domain\Activity\AttributeGains;
use App\Shared\Domain\Activity\Vitality;
use App\Shared\UI\Http\IdempotencyListener;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/shop` et `POST /api/shop/purchases` (#229) — l'étal, et le seul geste qu'on peut y
 * faire.
 *
 * Le catalogue livré porte neuf objets vendus (`WORN_RUNNING_SHOES`, `IRON_GAUNTLETS` — niveau
 * 1 —, `TRAVELERS_CLOAK`, `LUCKY_RABBIT_CHARM` — niveau 5 —, `TEMPERED_HEADBAND`,
 * `SWIFT_TRAIL_LEGGINGS`, `EMBER_OF_THE_ANCESTORS` — niveau 10 —, et les deux coffres du #230,
 * `WOODEN_CHEST` — niveau 1 — et `IRON_BOUND_CHEST` — niveau 10) et trois hors étal
 * (`OBSIDIAN_WARBLADE`, `STORMCALLERS_BOOTS`, `CROWN_OF_THE_TIRELESS`, les trois EPIC ou
 * LEGENDARY) — voir `items.yaml`.
 *
 * @phpstan-type ShopLine array{key: string, kind: string, name: string, rarity: string, slot: string|null, modifiers: list<array<string, mixed>>, priceCoins: int, imageUrl: string, affordable: bool, owned: bool, minimumLevel: int, unlocked: bool}
 * @phpstan-type ShopBody array{coins: int, items: list<ShopLine>}
 * @phpstan-type InventoryBody array{coins: int, equipment: array<string, mixed>, items: list<array<string, mixed>>}
 */
final class ShopRoutesTest extends ApiTestCase
{
    public function testANewAccountSeesTheWholeStallLockedAndUnaffordable(): void
    {
        $bob = $this->openAccount();

        $body = $this->shop($bob);

        self::assertSame(0, $body['coins']);
        self::assertCount(9, $body['items'], 'Les EPIC et LEGENDARY ne sont jamais à l\'étal.');

        foreach ($body['items'] as $item) {
            self::assertFalse($item['affordable'], (string) $item['key']);
            self::assertFalse($item['owned'], (string) $item['key']);
        }
    }

    /** L'ordre des clés de l'enveloppe, et de chaque objet, est du contrat versionné. */
    public function testTheKeyOrderIsFixed(): void
    {
        $bob = $this->openAccount();

        $body = $this->shop($bob);
        self::assertSame(['coins', 'items'], array_keys($body));

        $item = $body['items'][0];
        self::assertSame(
            ['key', 'kind', 'name', 'rarity', 'slot', 'modifiers', 'priceCoins', 'imageUrl', 'affordable', 'owned', 'minimumLevel', 'unlocked'],
            array_keys($item),
        );
    }

    public function testEveryCatalogImageIsAnAbsolutePublicUrl(): void
    {
        $bob = $this->openAccount();
        $item = $this->shop($bob)['items'][0];
        self::assertIsString($item['imageUrl']);
        self::assertMatchesRegularExpression('#^https?://#', $item['imageUrl']);
        $path = parse_url($item['imageUrl'], \PHP_URL_PATH);
        self::assertIsString($path);

        $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/png');
    }

    public function testNoEpicOrLegendaryItemEverAppearsOnTheStall(): void
    {
        $bob = $this->openAccount();

        $keys = array_column($this->shop($bob)['items'], 'key');

        foreach (['OBSIDIAN_WARBLADE', 'STORMCALLERS_BOOTS', 'CROWN_OF_THE_TIRELESS'] as $forbidden) {
            self::assertNotContains($forbidden, $keys);
        }
    }

    /** Un objet verrouillé par le niveau reste visible — un étal qui le cache ne donne envie de rien. */
    public function testALevelGatedItemStaysVisibleButLocked(): void
    {
        $bob = $this->openAccount();

        $cloak = self::itemNamed($this->shop($bob)['items'], 'TRAVELERS_CLOAK');

        self::assertSame(5, $cloak['minimumLevel']);
        self::assertFalse($cloak['unlocked']);
    }

    public function testAnAffordableItemIsFlaggedAsSuch(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 30);

        $shoes = self::itemNamed($this->shop($bob)['items'], 'WORN_RUNNING_SHOES');

        self::assertTrue($shoes['affordable']);
    }

    public function testAPurchasedItemIsFlaggedAsOwnedAndNoLongerAffordable(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 60);

        $this->purchase($bob, 'WORN_RUNNING_SHOES', 'achat-bottes');

        $shoes = self::itemNamed($this->shop($bob)['items'], 'WORN_RUNNING_SHOES');
        self::assertTrue($shoes['owned']);
    }

    public function testPurchasingWritesTheItemAndDebitsThePurse(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 100);

        $response = $this->purchase($bob, 'WORN_RUNNING_SHOES', 'achat-bottes');

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertSame(['item', 'spentCoins', 'coinsBefore', 'coinsAfter'], array_keys($body));
        self::assertSame(30, $body['spentCoins']);
        self::assertSame(100, $body['coinsBefore']);
        self::assertSame(70, $body['coinsAfter']);

        $item = $body['item'];
        self::assertIsArray($item);
        self::assertSame('WORN_RUNNING_SHOES', $item['key']);
        self::assertSame(self::translator()->nameOf('WORN_RUNNING_SHOES'), $item['name']);

        // La boutique referme la boucle : le sac le reçoit, la bourse en porte la trace.
        $inventory = $this->inventory($bob);
        self::assertCount(1, $inventory['items']);
        self::assertSame(70, $inventory['coins']);
    }

    /**
     * `sourceId` de la ligne `PURCHASE` est l'identifiant de la ligne d'inventaire achetée —
     * ce qui relie la dépense à ce qu'elle a acheté, sans clé étrangère.
     */
    public function testThePurchaseCoinLineSourceIdIsTheInventoryLineId(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 100);

        $inventoryBefore = $this->inventory($bob);
        self::assertSame([], $inventoryBefore['items']);

        $this->purchase($bob, 'WORN_RUNNING_SHOES', 'achat-bottes');

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        $sourceId = $connection->fetchOne(
            'SELECT source_id FROM rewards_coin_transaction WHERE reason = :reason',
            ['reason' => CoinReason::Purchase->value],
        );
        $inventoryId = $connection->fetchOne(
            "SELECT id FROM rewards_inventory_item WHERE item_key = 'WORN_RUNNING_SHOES'",
        );

        self::assertSame($inventoryId, $sourceId);
    }

    public function testReplayingTheSameIdempotencyKeyDoesNotDebitTwice(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 100);

        $first = $this->purchase($bob, 'WORN_RUNNING_SHOES', 'achat-bottes');
        $replay = $this->purchase($bob, 'WORN_RUNNING_SHOES', 'achat-bottes');

        self::assertSame('true', $replay->headers->get(IdempotencyListener::REPLAY_HEADER));
        self::assertSame($first->getContent(), $replay->getContent());
        self::assertSame(70, $this->shop($bob)['coins'], 'Un rejeu qui débiterait deux fois est le pire bug de cet écran.');
    }

    public function testPurchasingAnUnknownKeyIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->purchase($bob, 'GHOST_OF_AN_ITEM', 'achat-fantome');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-not-purchasable', self::decode($response)['type']);
    }

    /** Un objet du catalogue qui existe mais n'est jamais à l'étal — même refus qu'une clé inconnue. */
    public function testPurchasingAnItemOffTheStallIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->purchase($bob, 'OBSIDIAN_WARBLADE', 'achat-epee');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-not-purchasable', self::decode($response)['type']);
    }

    public function testPurchasingBelowTheMinimumLevelIsRefused(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 1_000);

        $response = $this->purchase($bob, 'TRAVELERS_CLOAK', 'achat-cape');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/shop-level-too-low', self::decode($response)['type']);
    }

    public function testReachingTheMinimumLevelUnlocksTheItem(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 1_000);
        $this->levelPlayerTo($bob->id, 3_060); // niveau 10 (levels.yaml) — largement au-dessus des 5 requis

        $cloak = self::itemNamed($this->shop($bob)['items'], 'TRAVELERS_CLOAK');
        self::assertTrue($cloak['unlocked']);

        $response = $this->purchase($bob, 'TRAVELERS_CLOAK', 'achat-cape');
        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testPurchasingAnAlreadyOwnedItemIsRefused(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 1_000);

        $this->purchase($bob, 'WORN_RUNNING_SHOES', 'premier-achat');
        $response = $this->purchase($bob, 'WORN_RUNNING_SHOES', 'second-achat');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/item-already-owned', self::decode($response)['type']);

        // Le refus n'a rien débité : une seule ligne d'inventaire, la bourse inchangée depuis
        // le premier achat.
        $inventory = $this->inventory($bob);
        self::assertCount(1, $inventory['items']);
        self::assertSame(970, $inventory['coins']);
    }

    /**
     * **Le coffre échappe au refus « déjà possédé » (#230).** `item-already-owned` se
     * justifie par un unique emplacement qui n'accueillerait rien de plus — voir son
     * docblock — un raisonnement qui ne tient pas pour un coffre : il s'empile, chaque achat
     * est une future ouverture de plus. La boutique étant le seul donneur de coffre en v1,
     * c'est même la seule façon d'en posséder plus d'un.
     */
    public function testAChestCanBePurchasedMoreThanOnce(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 1_000);

        $this->purchase($bob, 'WOODEN_CHEST', 'premier-coffre');
        $second = $this->purchase($bob, 'WOODEN_CHEST', 'second-coffre');

        self::assertSame(Response::HTTP_CREATED, $second->getStatusCode(), (string) $second->getContent());

        $quantities = array_column($this->inventory($bob)['items'], 'quantity', 'key');
        self::assertSame(2, $quantities['WOODEN_CHEST']);
    }

    public function testPurchasingWithoutEnoughCoinsIsRefused(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 10);

        $response = $this->purchase($bob, 'WORN_RUNNING_SHOES', 'achat-trop-cher');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('https://grrind.app/problems/insufficient-coin-balance', self::decode($response)['type']);
    }

    /**
     * **Le cœur du ticket.** `WORN_RUNNING_SHOES` et `IRON_GAUNTLETS` coûtent chacun 30 ; le
     * joueur n'a que de quoi en payer un. Aucune boucle réseau ne peut forcer deux vraies
     * requêtes à s'exécuter au même instant dans ce harnais — PHPUnit ne tourne pas
     * multi-processus — donc c'est, comme
     * {@see CoinLedgerPersistenceTest::testAWriteThatWouldCrossZeroIsRefused()},
     * le chemin réellement gardé qui est mis à l'épreuve : les deux écritures traversent
     * `CoinTransactionRepository::record()`, sous verrou, dans la vraie base — pas une
     * vérification applicative que deux requêtes concurrentes auraient pu passer toutes les
     * deux avant qu'aucune n'ait encore écrit.
     */
    public function testOnlyOnePurchaseGoesThroughWhenTheBalanceCoversExactlyOne(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, 30);

        $first = $this->purchase($bob, 'WORN_RUNNING_SHOES', 'premiere-tentative');
        $second = $this->purchase($bob, 'IRON_GAUNTLETS', 'seconde-tentative');

        self::assertSame(Response::HTTP_CREATED, $first->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $second->getStatusCode());
        self::assertSame('https://grrind.app/problems/insufficient-coin-balance', self::decode($second)['type']);

        self::assertSame(0, $this->shop($bob)['coins'], 'Le solde ne descend jamais sous zéro.');

        $inventory = $this->inventory($bob);
        self::assertCount(1, $inventory['items'], 'La seconde tentative refusée n\'a rien écrit.');
    }

    public function testAnonymousCallsAreRefused(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/shop')->getStatusCode());
        self::assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->send('POST', '/api/shop/purchases', ['itemKey' => 'WORN_RUNNING_SHOES'], ['Idempotency-Key' => 'sans-jeton'])->getStatusCode(),
        );
    }

    public function testPurchasingWithoutAnIdempotencyKeyIsRefused(): void
    {
        $bob = $this->openAccount();

        $response = $this->post('/api/shop/purchases', ['itemKey' => 'WORN_RUNNING_SHOES'], $bob->headers);

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @return ShopBody
     */
    private function shop(Account $account): array
    {
        $response = $this->get('/api/shop', $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsInt($body['coins']);
        self::assertIsArray($body['items']);

        /** @var ShopBody $body */
        return $body;
    }

    /**
     * @return InventoryBody
     */
    private function inventory(Account $account): array
    {
        $response = $this->get('/api/inventory', $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsInt($body['coins']);
        self::assertIsArray($body['equipment']);
        self::assertIsArray($body['items']);

        /** @var InventoryBody $body */
        return $body;
    }

    private function purchase(Account $account, string $itemKey, string $idempotencyKey): Response
    {
        return $this->send(
            'POST',
            '/api/shop/purchases',
            ['itemKey' => $itemKey],
            $account->headers + ['Idempotency-Key' => $idempotencyKey],
        );
    }

    private function credit(Uuid $userId, int $amount): void
    {
        $ledger = self::getContainer()->get(CoinLedger::class);
        self::assertInstanceOf(CoinLedger::class, $ledger);

        $ledger->credit($userId, CoinReason::WorkoutDrop, Uuid::v7(), $amount, new DateTimeImmutable());
    }

    /**
     * @param list<ShopLine> $items
     *
     * @return ShopLine
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

    private static function translator(): ItemTranslator
    {
        $translator = self::getContainer()->get(ItemTranslator::class);
        self::assertInstanceOf(ItemTranslator::class, $translator);

        return $translator;
    }

    /**
     * Verrouille, reprojette et écrit une ligne `progression_snapshot` directement, sans
     * passer par un import ou un `GrantXp` réel qui demanderait plusieurs journées de sport —
     * même geste qu'{@see \App\Tests\Combat\Application\FightBattleHandlerTest::levelPlayerTo()}.
     */
    private function levelPlayerTo(Uuid $playerId, int $totalXp): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $snapshots = self::getContainer()->get(ProgressionSnapshotRepository::class);
        self::assertInstanceOf(ProgressionSnapshotRepository::class, $snapshots);

        $curve = self::levelCurve();
        $vitality = self::vitalityRules();
        $attributes = new AttributeGains(0, 0, 0, 0);
        $now = new DateTimeImmutable();

        $entityManager->wrapInTransaction(static function () use ($snapshots, $playerId, $totalXp, $attributes, $curve, $vitality, $now): void {
            $snapshot = $snapshots->lockFor($playerId, $curve, $vitality);
            $snapshot->retotal($totalXp, $attributes, $curve, $vitality, $now);
        });
    }

    private static function levelCurve(): LevelCurve
    {
        $levels = self::getContainer()->getParameter('game.levels.levels');
        self::assertIsArray($levels);

        /** @var list<array{level: int, total_xp: int, skill_points: int}> $levels */
        return new LevelCurve($levels);
    }

    private static function vitalityRules(): Vitality
    {
        $container = self::getContainer();

        $floorPermille = $container->getParameter('game.attributes.vitality.floor_permille');
        self::assertIsInt($floorPermille);

        $targetActiveKcal = $container->getParameter('game.attributes.vitality.target_active_kcal');
        self::assertIsInt($targetActiveKcal);

        $bonusCapPermille = $container->getParameter('game.attributes.vitality.bonus_cap_permille');
        self::assertIsInt($bonusCapPermille);

        return new Vitality($floorPermille, $targetActiveKcal, $bonusCapPermille);
    }
}
