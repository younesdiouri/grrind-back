<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\ProgressionSnapshot;
use App\Rewards\Application\Inventory;
use App\Shared\Domain\Activity\Vitality;
use App\Shared\Infrastructure\Doctrine\IdempotencyRecordRepository;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\CommitFaultMiddleware;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** @phpstan-import-type Params from DriverManager */
final class IdempotencyTransactionTest extends ApiTestCase
{
    use Workouts;

    protected function tearDown(): void
    {
        CommitFaultMiddleware::$beforeCommit = null;
        CommitFaultMiddleware::$afterCommit = null;
        parent::tearDown();
    }

    /** @return iterable<string, array{int, bool}> */
    public static function failures(): iterable
    {
        yield 'exception 500' => [500, true];
        yield 'refus 422' => [422, true];
        yield 'réponse 500' => [500, false];
        yield 'réponse 422' => [422, false];
    }

    #[DataProvider('failures')]
    public function testFailureBeforeReceiptRollsBackTheRealSale(int $status, bool $throw): void
    {
        $bob = $this->openAccount();
        self::getContainer()->get(Inventory::class)->grant($bob->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        $this->client->disableReboot();
        $observer = $this->observer();
        $armed = true;
        $this->dispatcher()->addListener(KernelEvents::CONTROLLER_ARGUMENTS, function (ControllerArgumentsEvent $event) use (&$armed, $status, $throw, $observer): void {
            if (!$armed || '/api/inventory/sales' !== $event->getRequest()->getPathInfo()) {
                return;
            }
            $armed = false;
            $controller = $event->getController();
            $event->setController(function (mixed ...$arguments) use ($controller, $status, $throw, $observer): Response {
                $response = $controller(...$arguments);
                self::assertInstanceOf(Response::class, $response);
                self::assertSame(200, $response->getStatusCode());
                // Les vrais effets sont écrits, mais invisibles depuis une autre connexion.
                self::assertSame(0, $this->state()['quantity']);
                self::assertSame(1, $this->state()['sales']);
                self::assertSame(1, $observer->fetchOne('SELECT quantity FROM rewards_inventory_item'));
                self::assertSame(0, $observer->fetchOne('SELECT COUNT(*) FROM rewards_coin_transaction'));
                if ($throw) {
                    throw new HttpException($status, 'Panne après action avant reçu.');
                }

                return new JsonResponse(['failure' => true], $status);
            });
        }, 64);

        $payload = ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15];
        $headers = $bob->headers + ['Idempotency-Key' => 'before-receipt'];
        $failed = $this->post('/api/inventory/sales', $payload, $headers);
        self::assertSame($status, $failed->getStatusCode());
        self::assertSame(1, $this->state()['quantity']);
        self::assertSame(0, $this->state()['sales']);
        self::assertSame(0, $this->connection()->getTransactionNestingLevel());
        $retried = $this->post('/api/inventory/sales', $payload, $headers);
        if (500 === $status) {
            self::assertSame(200, $retried->getStatusCode(), (string) $retried->getContent());
            self::assertSame(0, $this->state()['quantity']);
            self::assertSame(1, $this->state()['sales']);
        } else {
            self::assertSame(422, $retried->getStatusCode());
            self::assertSame($failed->getContent(), $retried->getContent());
            self::assertSame('true', $retried->headers->get('Idempotent-Replay'));
            self::assertSame(1, $this->state()['quantity']);
        }
    }

    public function testReceiptWriteFailureRollsBackSaleAndAllowsRetry(): void
    {
        $bob = $this->openAccount();
        self::getContainer()->get(Inventory::class)->grant($bob->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        $db = $this->connection();
        $db->executeStatement("CREATE FUNCTION fail_receipt_366() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''Receipt unavailable''; END'");
        $db->executeStatement('CREATE TRIGGER fail_receipt_366 BEFORE UPDATE ON shared_idempotency_key FOR EACH ROW EXECUTE FUNCTION fail_receipt_366()');
        $payload = ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15];
        $headers = $bob->headers + ['Idempotency-Key' => 'receipt-write'];
        try {
            self::assertSame(500, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        } finally {
            $this->connection()->executeStatement('DROP TRIGGER fail_receipt_366 ON shared_idempotency_key');
            $this->connection()->executeStatement('DROP FUNCTION fail_receipt_366()');
        }
        self::assertSame(['quantity' => 1, 'sales' => 0, 'receipts' => []], $this->state());
        self::assertSame(200, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        self::assertSame(0, $this->state()['quantity']);
        self::assertSame(1, $this->state()['sales']);
    }

    public function testLostCommitAcknowledgementKeepsTheSaleAndOriginalReceipt(): void
    {
        $bob = $this->openAccount();
        self::getContainer()->get(Inventory::class)->grant($bob->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        $this->client->disableReboot();
        $observer = $this->observer();
        $pid = $this->connection()->fetchOne('SELECT pg_backend_pid()');
        $body = null;
        $afterCommit = static function () use ($observer, $pid, &$body): void {
            $body = $observer->fetchOne('SELECT response_body FROM shared_idempotency_key');
            self::assertTrue($observer->fetchOne('SELECT pg_terminate_backend(?)', [$pid]));
            throw new RuntimeException('Connexion perdue après COMMIT, avant accusé côté DBAL.');
        };
        $this->dispatcher()->addListener(KernelEvents::CONTROLLER_ARGUMENTS, static function () use ($afterCommit): void {
            CommitFaultMiddleware::$afterCommit = $afterCommit;
        });
        $payload = ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15];
        $headers = $bob->headers + ['Idempotency-Key' => 'commit-ack'];
        self::assertSame(500, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        self::assertIsString($body);
        $state = $this->state();
        self::assertSame(0, $state['quantity']);
        self::assertSame(1, $state['sales']);
        self::assertSame('COMPLETED', $state['receipts'][0]['status']);
        $retried = $this->post('/api/inventory/sales', $payload, $headers);
        self::assertSame(200, $retried->getStatusCode(), (string) $retried->getContent());
        self::assertSame($body, $retried->getContent());
        self::assertSame('true', $retried->headers->get('Idempotent-Replay'));
        self::assertSame($state, $this->state());
    }

    public function testDisconnectedBeforeCommitKeepsUncertainReservationUntilItsTtl(): void
    {
        $bob = $this->openAccount();
        self::getContainer()->get(Inventory::class)->grant($bob->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        $this->client->disableReboot();
        $observer = $this->observer();
        $pid = $this->connection()->fetchOne('SELECT pg_backend_pid()');
        $armed = true;
        $this->dispatcher()->addListener(KernelEvents::CONTROLLER_ARGUMENTS, static function () use (&$armed, $observer, $pid): void {
            if ($armed) {
                $armed = false;
                CommitFaultMiddleware::$beforeCommit = static function () use ($observer, $pid): void {
                    self::assertTrue($observer->fetchOne('SELECT pg_terminate_backend(?)', [$pid]));
                };
            }
        });
        $payload = ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15];
        $headers = $bob->headers + ['Idempotency-Key' => 'unknown-commit'];
        self::assertSame(500, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        self::assertSame(1, $this->state()['quantity']);
        self::assertSame(0, $this->state()['sales']);
        self::assertSame('IN_FLIGHT', $this->state()['receipts'][0]['status']);
        self::assertSame(409, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        // La rétention, pas une nouvelle clé ni un protocole de contournement.
        $observer->executeStatement("UPDATE shared_idempotency_key SET expires_at = NOW() - INTERVAL '1 hour'");
        self::assertSame(200, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        self::assertSame(0, $this->state()['quantity']);
        self::assertSame(1, $this->state()['sales']);
    }

    /** @return iterable<string, array{string}> */
    public static function inFlightStages(): iterable
    {
        yield 'avant écriture métier' => ['write'];
        yield 'reçu écrit avant COMMIT' => ['receipt'];
    }

    #[DataProvider('inFlightStages')]
    public function testConcurrentImportStaysInFlightAndKeepsLocksUntilReceiptCommit(string $stage): void
    {
        $bob = $this->openAccount();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $manager->persist(ProgressionSnapshot::untouched($bob->id, self::getContainer()->get(LevelCurve::class), self::getContainer()->get(Vitality::class), new DateTimeImmutable()));
        $manager->flush();
        // Une ligne visible avant l'import : SKIP LOCKED prouvera son verrou, pas son absence.
        self::assertSame([$bob->id->toRfc4122()], $this->connection()->fetchFirstColumn('SELECT user_id FROM progression_snapshot FOR UPDATE SKIP LOCKED'));
        $start = self::daysAgo(5);
        $workouts = [[
            'externalId' => 'concurrent-import', 'source' => 'APPLE_HEALTH', 'activityType' => 'running',
            'startedAt' => $start->format('c'), 'endedAt' => $start->modify('+30 minutes')->format('c'),
        ]];
        $input = new InputStream();
        $input->write(json_encode(['authorization' => $bob->headers['Authorization'], 'key' => 'concurrent-key', 'workouts' => $workouts, 'pauseAt' => $stage], \JSON_THROW_ON_ERROR)."\ncontinue\n");
        $worker = new Process([\PHP_BINARY, \dirname(__DIR__).'/Support/import_workouts_worker.php'], \dirname(__DIR__, 2));
        $worker->setInput($input);
        $worker->setTimeout(20);
        $worker->start();
        try {
            self::assertTrue($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, $stage."\n")), $worker->getErrorOutput());
            $this->client->disableReboot();
            $this->connection()->executeStatement("SET statement_timeout = '1s'");
            $headers = $bob->headers + ['Idempotency-Key' => 'concurrent-key'];
            $concurrent = $this->post('/api/workouts/import', ['workouts' => $workouts], $headers);
            self::assertSame(409, $concurrent->getStatusCode(), (string) $concurrent->getContent());
            self::assertSame('https://grrind.app/problems/idempotency-key-in-flight', self::decode($concurrent)['type']);
            self::assertTrue($worker->isRunning());
            self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM workout'));
            self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM xp_transaction'));
            self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
            self::assertFalse($this->connection()->fetchOne("SELECT pg_try_advisory_xact_lock(hashtext('training.import'), hashtext(?))", [$bob->id->toRfc4122()]));
            if ('receipt' === $stage) {
                self::assertSame([], $this->connection()->fetchFirstColumn('SELECT user_id FROM progression_snapshot FOR UPDATE SKIP LOCKED'));
            }
            $input->write("continue\n");
            self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
            if (1 !== preg_match('/result:(.+)/', $worker->getOutput(), $match)) {
                self::fail('Le processus doit annoncer sa réponse HTTP.');
            }
            $original = json_decode($match[1], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($original);
            self::assertSame(200, $original['status']);
            self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM workout'));
            $xp = $this->connection()->fetchOne('SELECT SUM(amount) FROM xp_transaction');
            self::assertIsNumeric($xp);
            self::assertSame(30, (int) $xp);
            self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
            $replay = $this->post('/api/workouts/import', ['workouts' => $workouts], $headers);
            self::assertSame(200, $replay->getStatusCode());
            self::assertSame('true', $replay->headers->get('Idempotent-Replay'));
            self::assertSame($original['body'], self::decode($replay));
        } finally {
            $worker->stop(0);
            $this->connection()->executeStatement('SET statement_timeout = 0');
        }
    }

    public function testRecycledReservationCannotBeCompletedOrReleasedByItsOldHolder(): void
    {
        $bob = $this->openAccount();
        $records = self::getContainer()->get(IdempotencyRecordRepository::class);
        $old = $records->claim($bob->id, 'expired', str_repeat('a', 64), new DateTimeImmutable('-25 hours'));
        $new = $records->claim($bob->id, 'expired', str_repeat('b', 64), new DateTimeImmutable());
        self::assertNotNull($old);
        self::assertNotNull($new);
        self::assertFalse($old->equals($new));
        $records->release($old);
        try {
            $records->complete($old, 200, [], '{}');
            self::fail('Une réservation remplacée ne doit plus pouvoir être complétée.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('réservation idempotente', $error->getMessage());
        }
        $records->complete($new, 200, [], '{"original":true}');
        $records->release($new);
        self::assertSame('{"original":true}', $records->ofKey($bob->id, 'expired')?->responseBody());
    }

    public function testImportReceiptFailureRollsBackTheWholeBatchAndOutbox(): void
    {
        $bob = $this->openAccount();
        $this->client->disableReboot();
        $armed = true;
        $this->dispatcher()->addListener(KernelEvents::CONTROLLER_ARGUMENTS, function (ControllerArgumentsEvent $event) use (&$armed): void {
            if (!$armed || '/api/workouts/import' !== $event->getRequest()->getPathInfo()) {
                return;
            }
            $armed = false;
            $controller = $event->getController();
            $event->setController(function (mixed ...$arguments) use ($controller): Response {
                $controller(...$arguments);
                self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM workout'));
                self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM xp_transaction'));
                self::assertSame(4, $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
                throw new RuntimeException('Panne après le lot et avant le reçu.');
            });
        }, 64);
        $workouts = [];
        foreach ([5, 6] as $days) {
            $start = self::daysAgo($days);
            $workouts[] = ['externalId' => 'batch-'.$days, 'source' => 'APPLE_HEALTH', 'activityType' => 'running', 'startedAt' => $start->format('c'), 'endedAt' => $start->modify('+30 minutes')->format('c')];
        }
        $headers = $bob->headers + ['Idempotency-Key' => 'batch-receipt'];
        self::assertSame(500, $this->post('/api/workouts/import', ['workouts' => $workouts], $headers)->getStatusCode());
        foreach (['workout', 'xp_transaction', 'progression_snapshot', 'rewards_loot_roll', 'rewards_inventory_item', 'messenger_messages', 'shared_idempotency_key'] as $table) {
            self::assertSame(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM '.$table), $table);
        }
        $retried = $this->post('/api/workouts/import', ['workouts' => $workouts], $headers);
        self::assertSame(200, $retried->getStatusCode(), (string) $retried->getContent());
        self::assertIsArray(self::decode($retried)['imported']);
        self::assertCount(2, self::decode($retried)['imported']);
        $replay = $this->post('/api/workouts/import', ['workouts' => $workouts], $headers);
        self::assertSame($retried->getContent(), $replay->getContent());
        self::assertSame('true', $replay->headers->get('Idempotent-Replay'));
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM workout'));
        $xp = $this->connection()->fetchOne('SELECT SUM(amount) FROM xp_transaction');
        self::assertIsNumeric($xp);
        self::assertSame(60, (int) $xp);
        self::assertSame(4, $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }

    public function testContentAndRouteConflictsAndAccountIsolationOnRealSales(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        foreach ([$bob, $alice] as $account) {
            self::getContainer()->get(Inventory::class)->grant($account->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        }
        $payload = ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15];
        $headers = $bob->headers + ['Idempotency-Key' => 'scoped-sale'];
        self::assertSame(200, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        $conflict = $this->post('/api/inventory/sales', array_replace($payload, ['expectedSellPriceCoins' => 99]), $headers);
        self::assertSame(422, $conflict->getStatusCode());
        self::assertSame('https://grrind.app/problems/idempotency-key-reused', self::decode($conflict)['type']);
        $otherRoute = $this->post('/api/shop/purchases', ['itemKey' => 'WORN_RUNNING_SHOES'], $headers);
        self::assertSame(422, $otherRoute->getStatusCode());
        self::assertSame('https://grrind.app/problems/idempotency-key-reused', self::decode($otherRoute)['type']);
        self::assertSame(200, $this->post('/api/inventory/sales', $payload, $alice->headers + ['Idempotency-Key' => 'scoped-sale'])->getStatusCode());
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM rewards_coin_transaction'));
        self::assertSame([0, 0], $this->connection()->fetchFirstColumn('SELECT quantity FROM rewards_inventory_item'));
    }

    public function testConcurrentReservationDoesNotReplayAnExpiredReceipt(): void
    {
        $bob = $this->openAccount();
        foreach ([1, 2] as $unused) {
            self::getContainer()->get(Inventory::class)->grant($bob->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        }
        $payload = ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15];
        $headers = $bob->headers + ['Idempotency-Key' => 'expired-concurrent'];
        self::assertSame(200, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        $observer = $this->observer();
        $observer->executeStatement("UPDATE shared_idempotency_key SET expires_at = NOW() - INTERVAL '1 hour'");
        $observer->beginTransaction();
        try {
            $observer->executeStatement("SELECT pg_advisory_xact_lock(hashtext('shared.idempotency'), hashtext(?))", [$bob->id->toRfc4122().':expired-concurrent']);
            $concurrent = $this->post('/api/inventory/sales', $payload, $headers);
            self::assertSame(409, $concurrent->getStatusCode());
            self::assertSame('https://grrind.app/problems/idempotency-key-in-flight', self::decode($concurrent)['type']);
            self::assertSame(1, $this->state()['quantity']);
        } finally {
            $observer->rollBack();
        }
        self::assertSame(200, $this->post('/api/inventory/sales', $payload, $headers)->getStatusCode());
        self::assertSame(0, $this->state()['quantity']);
        self::assertSame(2, $this->state()['sales']);
    }

    public function testResponseFailureAfterSaleKeepsTheCommittedReceiptAndSameKeyReplays(): void
    {
        $bob = $this->openAccount();
        $inventory = self::getContainer()->get(Inventory::class);
        $inventory->grant($bob->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        $inventory->grant($bob->id, 'WORN_RUNNING_SHOES', Uuid::v7(), new DateTimeImmutable());
        $this->client->disableReboot();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $armed = true;
        $dispatcher->addListener(KernelEvents::RESPONSE, static function (ResponseEvent $event) use (&$armed): void {
            if ($armed && '/api/inventory/sales' === $event->getRequest()->getPathInfo() && 200 === $event->getResponse()->getStatusCode()) {
                $armed = false;
                throw new RuntimeException('Panne après vente, avant reçu.');
            }
        }, 64);

        $payload = ['itemKey' => 'WORN_RUNNING_SHOES', 'expectedSellPriceCoins' => 15];
        $headers = $bob->headers + ['Idempotency-Key' => 'sale-failure'];
        $failed = $this->post('/api/inventory/sales', $payload, $headers);
        self::assertSame(500, $failed->getStatusCode());
        $afterFailure = $this->state();
        $retried = $this->post('/api/inventory/sales', $payload, $headers);
        self::assertSame(200, $retried->getStatusCode(), (string) $retried->getContent());
        $afterRetry = $this->state();

        self::assertSame(1, $afterFailure['quantity'], json_encode(['afterFailure' => $afterFailure, 'afterRetry' => $afterRetry], \JSON_THROW_ON_ERROR));
        self::assertSame(1, $afterFailure['sales']);
        self::assertSame('COMPLETED', $afterFailure['receipts'][0]['status']);
        self::assertSame($afterFailure, $afterRetry);
        self::assertSame(1, $afterRetry['quantity']);
        self::assertSame(1, $afterRetry['sales']);
        $replayed = $this->post('/api/inventory/sales', $payload, $headers);
        self::assertSame($retried->getContent(), $replayed->getContent());
        self::assertSame('true', $replayed->headers->get('Idempotent-Replay'));
        self::assertSame($afterRetry, $this->state());
    }

    /** @return array{quantity: mixed, sales: mixed, receipts: list<array<string, mixed>>} */
    private function state(): array
    {
        return [
            'quantity' => $this->connection()->fetchOne('SELECT quantity FROM rewards_inventory_item'),
            'sales' => $this->connection()->fetchOne("SELECT COUNT(*) FROM rewards_coin_transaction WHERE reason = 'SALE'"),
            'receipts' => $this->connection()->fetchAllAssociative('SELECT status, response_status, response_body FROM shared_idempotency_key'),
        ];
    }

    private function dispatcher(): EventDispatcherInterface
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        return $dispatcher;
    }

    private function observer(): Connection
    {
        /** @var Params $parameters */
        $parameters = $this->connection()->getParams();

        return DriverManager::getConnection($parameters);
    }
}
