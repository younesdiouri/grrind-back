<?php

declare(strict_types=1);

namespace App\Tests\Rewards;

use App\Rewards\Application\CoinLedger;
use App\Rewards\Domain\CoinReason;
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
 * **Le tri est celui du ledger — l'ordre d'écriture — pas celui du fait.** C'est la
 * décision documentée sur `CoinTransactionRepository::history()`, et c'est ce que
 * {@see testEntriesComeInLedgerOrderNotFactOrder()} prouve : des écritures créditées dans
 * le désordre de leur `occurredAt` — exactement le cas d'un import qui remonte plusieurs
 * jours d'un coup — ressortent dans l'ordre où elles ont été écrites, pas dans celui du
 * sport.
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
     * un import qui remonte du plus récent au plus ancien produirait exactement ce lot.
     * L'historique les rend dans l'ordre où elles ont été *écrites*, jamais dans celui de
     * `occurredAt`.
     */
    public function testEntriesComeInLedgerOrderNotFactOrder(): void
    {
        $bob = $this->openAccount();

        $writtenFirst = $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable('2026-08-01T08:00:00+00:00'));
        $writtenSecond = $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable('2026-08-15T08:00:00+00:00'));
        $writtenThird = $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable('2026-07-01T08:00:00+00:00'));

        self::assertSame(
            [$writtenThird, $writtenSecond, $writtenFirst],
            $this->idsOf($this->history($bob)),
            'Le plus récemment écrit vient en premier, quelle que soit la date du fait.',
        );
    }

    public function testWalksThePagesWithoutRepeatingNorSkipping(): void
    {
        $bob = $this->openAccount();

        $written = [];
        for ($i = 0; $i < 5; ++$i) {
            $written[] = $this->credit($bob->id, CoinReason::WorkoutDrop, 1, new DateTimeImmutable());
        }
        // Le plus récemment écrit vient en premier.
        $expected = array_reverse($written);

        $firstPage = $this->history($bob, ['limit' => 2]);
        self::assertSame(\array_slice($expected, 0, 2), $this->idsOf($firstPage));
        self::assertIsString($firstPage['nextCursor']);

        $secondPage = $this->history($bob, ['limit' => 2, 'cursor' => $firstPage['nextCursor']]);
        self::assertSame(\array_slice($expected, 2, 2), $this->idsOf($secondPage));
        self::assertIsString($secondPage['nextCursor']);

        $lastPage = $this->history($bob, ['limit' => 2, 'cursor' => $secondPage['nextCursor']]);
        self::assertSame(\array_slice($expected, 4, 1), $this->idsOf($lastPage));
        self::assertNull($lastPage['nextCursor']);
    }

    public function testAnAccountNeverSeesAnothersHistory(): void
    {
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $bob = $this->openAccount();

        $this->credit($alice->id, CoinReason::WorkoutDrop, 999, new DateTimeImmutable());
        $bobsTransaction = $this->credit($bob->id, CoinReason::WorkoutDrop, 5, new DateTimeImmutable());

        $body = $this->history($bob);
        self::assertSame(5, $body['balance']);
        self::assertSame([$bobsTransaction], $this->idsOf($body));
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
