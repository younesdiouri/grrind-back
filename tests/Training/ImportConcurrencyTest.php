<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Deux vrais imports HTTP, deux kernels/connexions et des barrières sans temporisation. */
final class ImportConcurrencyTest extends ApiTestCase
{
    use Workouts;

    /** @var list<Process> */
    private array $workers = [];

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(0);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{int, string, int, int}> */
    public static function overlappingImports(): iterable
    {
        yield 'course créditée' => [5, 'running', 30, 1];
        yield 'archive sans progression' => [40, 'running', 0, 0];
        yield 'marche sans XP' => [5, 'walking', 0, 1];
    }

    #[DataProvider('overlappingImports')]
    public function testOverlappingProviderIdsAdmitOnlyOneEffort(int $days, string $activity, int $xp, int $imported): void
    {
        $bob = $this->openAccount();
        $a = [$this->candidate('first', $days, $activity)];
        $b = [$this->candidate('second', $days, $activity)];
        [$first, $second, $blocked] = $this->race($bob, $a, $b);

        self::assertSame([200, 200], [$first['status'], $second['status']]);
        self::assertSame(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM workout'), $this->state());
        self::assertIsArray($first['body']['imported']);
        self::assertCount($imported, $first['body']['imported']);
        self::assertSame([['externalId' => 'second', 'activityType' => $activity, 'reason' => 'OVERLAPS']], $second['body']['skipped']);
        $this->assertXp($xp);
        self::assertTrue($blocked, 'Le second import doit attendre avant toute écriture.');
        $this->assertReplay($bob, $a, 'first-request', $first);
        $this->assertReplay($bob, $b, 'second-request', $second);
    }

    public function testSameProviderIdIsABusinessDuplicateWithoutLosingTheRestOfTheBatch(): void
    {
        $bob = $this->openAccount();
        $shared = $this->candidate('shared');
        // La séance libre est écrite avant le doublon : une violation SQL annulerait aussi celle-ci.
        [$first, $second, $blocked] = $this->race($bob, [$shared], [$this->candidate('independent', 6), $shared]);

        self::assertSame([200, 200], [$first['status'], $second['status']], $this->state());
        self::assertSame(['independent', 'shared'], $this->connection()->fetchFirstColumn('SELECT external_id FROM workout ORDER BY external_id'));
        self::assertIsArray($second['body']['imported']);
        self::assertCount(1, $second['body']['imported']);
        self::assertSame([['externalId' => 'shared', 'activityType' => 'running', 'reason' => 'ALREADY_IMPORTED']], $second['body']['skipped']);
        $this->assertXp(60);
        self::assertTrue($blocked);
    }

    public function testDistinctIntervalsAreBothAdmittedAndKeepTheDailyCapAndMeasuredDuration(): void
    {
        $bob = $this->openAccount();
        $a = $this->candidate('morning', time: '04:00:00', seconds: 5 * 3600);
        $b = $this->candidate('evening', time: '14:00:00', seconds: 5 * 3600);
        [$first, $second, $blocked] = $this->race($bob, [$a], [$b]);

        self::assertSame([200, 200], [$first['status'], $second['status']]);
        self::assertIsArray($first['body']['imported']);
        self::assertIsArray($second['body']['imported']);
        self::assertCount(1, $first['body']['imported']);
        self::assertCount(1, $second['body']['imported']);
        self::assertSame([18000, 18000], $this->connection()->fetchFirstColumn('SELECT duration_seconds FROM workout ORDER BY started_at'));
        self::assertSame([240, 160], $this->connection()->fetchFirstColumn('SELECT amount FROM xp_transaction ORDER BY occurred_at'));
        $this->assertXp(400);
        self::assertTrue($blocked);
    }

    public function testFailureMidBatchRollsBackAndReleasesTheWaitingImport(): void
    {
        $bob = $this->openAccount();
        [$first, $second, $blocked] = $this->race($bob, [$this->candidate('rolled-back', 6), $this->candidate('failure')], [$this->candidate('survivor', 6)], 2);

        self::assertSame([500, 200], [$first['status'], $second['status']]);
        self::assertSame(['survivor'], $this->connection()->fetchFirstColumn('SELECT external_id FROM workout'));
        $this->assertXp(30);
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        self::assertTrue($blocked);
    }

    public function testAnotherAccountDoesNotWaitForTheImportLock(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        [$first, $input] = $this->worker($bob, [$this->candidate('bob')], 'bob-request');
        $input->write("continue\n");
        $this->awaitOutput($first, 'write');
        $response = $this->post('/api/workouts/import', ['workouts' => [$this->candidate('alice')]], $alice->headers + ['Idempotency-Key' => 'alice-request']);
        self::assertSame(200, $response->getStatusCode());
        $input->write("continue\n");
        self::assertSame(200, $this->workerResult($first)['status']);
    }

    /**
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     *
     * @return array{array{status: int, body: array<mixed>}, array{status: int, body: array<mixed>}, bool}
     */
    private function race(Account $account, array $a, array $b, ?int $failAfter = null): array
    {
        [$first, $firstInput] = $this->worker($account, $a, 'first-request', $failAfter);
        [$second, $secondInput] = $this->worker($account, $b, 'second-request');
        $firstInput->write("continue\n");
        $this->awaitOutput($first, 'write');
        $secondInput->write("continue\n");
        $blocked = $this->awaitBlockedOrWrite($second, $first);
        $firstInput->write("continue\n");
        $firstResult = $this->workerResult($first);
        // Sur main, les deux ont déjà lu le créneau libre ; après correction, le second le relit après COMMIT.
        $secondInput->write("continue\n");

        return [$firstResult, $this->workerResult($second), $blocked];
    }

    /**
     * @param list<array<string, mixed>> $workouts
     *
     * @return array{Process, InputStream}
     */
    private function worker(Account $account, array $workouts, string $key, ?int $failAfter = null): array
    {
        $input = new InputStream();
        $input->write(json_encode(['authorization' => $account->headers['Authorization'], 'key' => $key, 'workouts' => $workouts, 'failAfter' => $failAfter], \JSON_THROW_ON_ERROR)."\n");
        $process = new Process([\PHP_BINARY, \dirname(__DIR__).'/Support/import_workouts_worker.php'], \dirname(__DIR__, 2));
        $process->setInput($input);
        $process->setTimeout(20);
        $process->start();
        $this->workers[] = $process;
        $this->awaitOutput($process, 'ready');

        return [$process, $input];
    }

    private function awaitOutput(Process $process, string $stage): void
    {
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($process->getOutput(), $stage."\n")) {
                return;
            }
            $process->checkTimeout();
        } while ($process->isRunning() && microtime(true) < $deadline);
        self::fail('Barrière absente : '.$stage.' '.$process->getOutput().' '.$process->getErrorOutput());
    }

    /** Une attente PostgreSQL prouvée par le PID bloqueur, ou la barrière atteinte trop tôt. */
    private function awaitBlockedOrWrite(Process $second, Process $first): bool
    {
        if (1 !== preg_match('/pid:(\d+)/', $second->getOutput(), $secondPid)
            || 1 !== preg_match('/pid:(\d+)/', $first->getOutput(), $firstPid)) {
            self::fail('Les imports doivent annoncer leurs PID PostgreSQL.');
        }
        $connection = $this->connection();
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($second->getOutput(), "write\n") || str_contains($second->getOutput(), 'result:')) {
                return false;
            }
            if ($connection->fetchOne('SELECT ? = ANY(pg_blocking_pids(?))', [(int) $firstPid[1], (int) $secondPid[1]])) {
                return true;
            }
            $second->checkTimeout();
        } while ($second->isRunning() && microtime(true) < $deadline);
        self::fail('Le second import ne rejoint ni sa barrière ni le verrou : '.$second->getErrorOutput());
    }

    /** @return array{status: int, body: array<mixed>} */
    private function workerResult(Process $process): array
    {
        self::assertSame(0, $process->wait(), $process->getErrorOutput());
        if (1 !== preg_match('/result:(.+)/', $process->getOutput(), $matches)) {
            self::fail('Le processus doit annoncer sa réponse HTTP.');
        }
        $result = json_decode($matches[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsInt($result['status']);
        self::assertIsArray($result['body']);

        return ['status' => $result['status'], 'body' => $result['body']];
    }

    /** @return array<string, mixed> */
    private function candidate(string $id, int $days = 5, string $activity = 'running', string $time = '07:00:00', int $seconds = 1800): array
    {
        $start = self::daysAgo($days, $time);

        return [
            'externalId' => $id,
            'source' => 'APPLE_HEALTH',
            'activityType' => $activity,
            'startedAt' => $start->format(DateTimeInterface::ATOM),
            'endedAt' => $start->modify('+'.$seconds.' seconds')->format(DateTimeInterface::ATOM),
        ];
    }

    private function assertXp(int $expected): void
    {
        $ledger = $this->connection()->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM xp_transaction');
        $snapshot = $this->connection()->fetchOne('SELECT COALESCE(SUM(total_xp), 0) FROM progression_snapshot');
        self::assertIsNumeric($ledger);
        self::assertIsNumeric($snapshot);
        self::assertSame($expected, (int) $ledger);
        self::assertSame($expected, (int) $snapshot);
    }

    /**
     * @param list<array<string, mixed>>             $workouts
     * @param array{status: int, body: array<mixed>} $original
     */
    private function assertReplay(Account $account, array $workouts, string $key, array $original): void
    {
        $response = $this->post('/api/workouts/import', ['workouts' => $workouts], $account->headers + ['Idempotency-Key' => $key]);
        self::assertSame($original['status'], $response->getStatusCode());
        self::assertSame('true', $response->headers->get('Idempotent-Replay'));
        self::assertSame($original['body'], self::decode($response));
    }

    private function state(): string
    {
        return json_encode([
            'workouts' => $this->connection()->fetchAllAssociative('SELECT external_id, duration_seconds FROM workout ORDER BY started_at'),
            'ledgerXp' => $this->connection()->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM xp_transaction'),
            'snapshotXp' => $this->connection()->fetchOne('SELECT COALESCE(SUM(total_xp), 0) FROM progression_snapshot'),
        ], \JSON_THROW_ON_ERROR);
    }
}
