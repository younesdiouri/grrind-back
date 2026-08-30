<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\CoinLedger;
use App\Rewards\Domain\CoinReason;
use App\Shared\UI\Http\Cursor;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /api/inventory/coins` (#30) — le solde et le détail des mouvements, paginé au
 * curseur dans la forme de `GET /api/battles` et `GET /api/progression/history`.
 *
 * **Le tri est celui du fait — `occurredAt` — pas celui de l'écriture.** Une pièce créditée
 * par un vieux workout doit se ranger à la date de ce workout, exactement comme le ledger
 * d'XP et l'historique des combats : voir le docblock de
 * `CoinTransactionRepository::history()`. {@see testEntriesComeInFactOrderNotWriteOrder()}
 * le prouve en créditant dans le désordre de la date — le cas d'un import qui remonte
 * plusieurs jours d'un coup.
 */
final class CoinHistoryRoutesTest extends ApiTestCase
{
    public function testAnEmptyHistoryIsAnEmptyListAndNotAnError(): void
    {
        $bob = $this->openAccount();

        $body = $this->history($bob);

        self::assertSame(0, $body['balance']);
        self::assertSame([], $body['transactions']);
        self::assertNull($body['nextCursor']);
    }

    public function testTheBalanceIsReturnedAlongsideTheHistory(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, CoinReason::WorkoutDrop, 10, new DateTimeImmutable('2026-08-01T08:00:00+00:00'));
        $this->credit($bob->id, CoinReason::BattleDrop, 7, new DateTimeImmutable('2026-08-02T08:00:00+00:00'));

        self::assertSame(17, $this->history($bob)['balance']);
    }

    /**
     * **Le cœur du ticket.** Trois écritures créditées dans l'ordre inverse de leur date —
     * exactement ce que produirait un import qui remonte du plus récent workout au plus
     * ancien. L'historique les rend dans l'ordre de `occurredAt`, jamais dans celui où elles
     * ont été écrites.
     */
    public function testEntriesComeInFactOrderNotWriteOrder(): void
    {
        $bob = $this->openAccount();

        $mostRecentFact = $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable('2026-08-15T08:00:00+00:00'));
        $middleFact = $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable('2026-08-08T08:00:00+00:00'));
        $oldestFact = $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable('2026-08-01T08:00:00+00:00'));

        // Écrites du fait le plus récent au plus ancien : l'inverse de ce qu'un import
        // produirait. Si le tri suivait l'ordre d'écriture, cette liste sortirait telle
        // quelle plutôt que dans l'ordre attendu ci-dessous.
        self::assertSame(
            [$mostRecentFact, $middleFact, $oldestFact],
            $this->idsOf($this->history($bob)),
            'Le fait le plus récent vient en premier, quel que soit l\'ordre d\'écriture.',
        );
    }

    public function testWalksThePagesWithoutRepeatingNorSkipping(): void
    {
        $bob = $this->openAccount();

        $all = [];
        for ($day = 5; $day >= 1; --$day) {
            $all[] = $this->credit($bob->id, CoinReason::WorkoutDrop, 1, new DateTimeImmutable(\sprintf('2026-07-0%dT08:00:00+00:00', $day)));
        }

        $firstPage = $this->history($bob, ['limit' => 2]);
        self::assertSame(\array_slice($all, 0, 2), $this->idsOf($firstPage));
        self::assertIsString($firstPage['nextCursor']);

        // Un mouvement livré pendant le défilement ne décale rien : il se range à sa date et
        // n'a aucun effet sur les pages déjà servies.
        $intercale = $this->credit($bob->id, CoinReason::WorkoutDrop, 1, new DateTimeImmutable('2026-06-01T08:00:00+00:00'));

        $secondPage = $this->history($bob, ['limit' => 2, 'cursor' => $firstPage['nextCursor']]);
        self::assertSame(\array_slice($all, 2, 2), $this->idsOf($secondPage));
        self::assertIsString($secondPage['nextCursor']);

        $lastPage = $this->history($bob, ['limit' => 2, 'cursor' => $secondPage['nextCursor']]);
        self::assertSame([$all[4], $intercale], $this->idsOf($lastPage));
        self::assertNull($lastPage['nextCursor']);
    }

    /**
     * **Le cas que le curseur composite existe pour couvrir.** Deux mouvements crédités à la
     * même seconde ne doivent être ni rendus deux fois ni sautés — un curseur qui ne
     * porterait que la date s'arrêterait entre les deux sans savoir lequel il a déjà servi.
     */
    public function testTwoTransactionsAtTheSameSecondAreNeitherRepeatedNorSkipped(): void
    {
        $bob = $this->openAccount();
        $sameSecond = new DateTimeImmutable('2026-07-15T08:30:00+00:00');

        $this->credit($bob->id, CoinReason::WorkoutDrop, 1, $sameSecond);
        $this->credit($bob->id, CoinReason::WorkoutDrop, 1, $sameSecond);
        $this->credit($bob->id, CoinReason::WorkoutDrop, 1, $sameSecond);

        $seen = [];
        $cursor = null;

        do {
            $page = $this->history($bob, null === $cursor ? ['limit' => 1] : ['limit' => 1, 'cursor' => $cursor]);
            $seen = [...$seen, ...$this->idsOf($page)];
            $cursor = $page['nextCursor'];
        } while (null !== $cursor);

        self::assertCount(3, $seen);
        self::assertSame($seen, array_unique($seen), 'Aucun mouvement ne doit être rendu deux fois.');
    }

    public function testAnAccountNeverSeesAnothersHistoryEvenByForcingTheCursor(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $aliceOccurredAt = new DateTimeImmutable('2026-07-16T08:00:00+00:00');
        $alicesTransaction = $this->credit($alice->id, CoinReason::WorkoutDrop, 999, $aliceOccurredAt);
        $earlier = $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable('2026-07-15T08:00:00+00:00'));
        $later = $this->credit($bob->id, CoinReason::WorkoutDrop, 3, new DateTimeImmutable('2026-07-17T08:00:00+00:00'));

        $body = $this->history($bob);
        self::assertSame(8, $body['balance']);
        self::assertSame([$later, $earlier], $this->idsOf($body));

        // Le curseur d'Alice, rejoué par Bob : la position se lit sur le mouvement de Bob
        // dont la date précède celle d'Alice — jamais sur celui d'Alice, qui n'apparaît à
        // aucun moment dans la page de Bob.
        $forgedCursor = Cursor::of($aliceOccurredAt, Uuid::fromString($alicesTransaction))->encoded();
        self::assertSame([$earlier], $this->idsOf($this->history($bob, ['cursor' => $forgedCursor])));
    }

    public function testRefusesALimitBeyondTheCeiling(): void
    {
        $bob = $this->openAccount();

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get('/api/inventory/coins?limit=500', $bob->headers)->getStatusCode());
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->get('/api/inventory/coins?limit=0', $bob->headers)->getStatusCode());
    }

    public function testRefusesAnUnreadableCursor(): void
    {
        $bob = $this->openAccount();

        $response = $this->get('/api/inventory/coins?cursor=pas-un-curseur', $bob->headers);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
    }

    public function testRefusesAnonymousCalls(): void
    {
        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->get('/api/inventory/coins')->getStatusCode());
    }

    public function testTheKeyOrderOfAnEntryIsFixed(): void
    {
        $bob = $this->openAccount();
        $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable());

        $body = $this->history($bob);
        self::assertSame(['balance', 'transactions', 'nextCursor'], array_keys($body));

        $transaction = $body['transactions'][0];
        self::assertIsArray($transaction);
        self::assertSame(['id', 'sourceId', 'reason', 'amount', 'occurredAt'], array_keys($transaction));
        self::assertSame('WORKOUT_DROP', $transaction['reason']);
        self::assertSame(5, $transaction['amount']);
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return array{balance: int, transactions: list<array<string, mixed>>, nextCursor: string|null}
     */
    private function history(Account $account, array $parameters = []): array
    {
        $response = $this->get('/api/inventory/coins?'.http_build_query($parameters), $account->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsInt($body['balance']);
        self::assertIsArray($body['transactions']);
        self::assertTrue(null === $body['nextCursor'] || \is_string($body['nextCursor']));

        /** @var array{balance: int, transactions: list<array<string, mixed>>, nextCursor: string|null} $body */
        return $body;
    }

    /**
     * @param array{balance: int, transactions: list<array<string, mixed>>, nextCursor: string|null} $page
     *
     * @return list<string>
     */
    private function idsOf(array $page): array
    {
        return array_map(static function (array $transaction): string {
            self::assertIsString($transaction['id']);

            return $transaction['id'];
        }, $page['transactions']);
    }

    private function credit(Uuid $userId, CoinReason $reason, int $amount, DateTimeImmutable $occurredAt): string
    {
        $ledger = self::getContainer()->get(CoinLedger::class);
        self::assertInstanceOf(CoinLedger::class, $ledger);

        $sourceId = Uuid::v7();
        $ledger->credit($userId, $reason, $sourceId, $amount, $occurredAt);

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $id = $connection->fetchOne(
            'SELECT id FROM rewards_coin_transaction WHERE user_id = :userId AND source_id = :sourceId',
            ['userId' => $userId->toRfc4122(), 'sourceId' => $sourceId->toRfc4122()],
        );
        self::assertIsString($id);

        return $id;
    }
}
