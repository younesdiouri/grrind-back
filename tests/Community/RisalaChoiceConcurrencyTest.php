<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\RevealRisalat;
use App\Community\Application\RevealRisalatHandler;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildRules;
use App\Community\Domain\Risala;
use App\Community\Domain\RisalaRotation;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Doctrine\RisalaRepository;
use App\Shared\Domain\Activity\CreditingDisciplines;
use App\Shared\Domain\Activity\Discipline;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/** Deux kernels/connexions PostgreSQL ; barrières avant SQL et attente de verrou constatée. */
final class RisalaChoiceConcurrencyTest extends ApiTestCase
{
    private const string BEFORE = '2030-01-06T18:59:57+00:00';
    private const string DEADLINE = '2030-01-06T19:00:00+00:00';

    private ?Process $worker = null;
    private ?Connection $lockedConnection = null;
    private ?string $clockPath = null;

    protected function tearDown(): void
    {
        $this->worker?->stop(0);
        if ($this->lockedConnection?->isTransactionActive()) {
            $this->lockedConnection->rollBack();
        }
        if (null !== $this->clockPath) {
            unlink($this->clockPath);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function sealedTurns(): iterable
    {
        yield 'SENT garde le choix commis' => [true];
        yield 'MISSED reste sans choix' => [false];
    }

    #[DataProvider('sealedTurns')]
    public function testAChoiceWaitingBehindRevealCannotChangeTheSealedTurn(bool $chosen): void
    {
        [$sender, $guild, $turn] = $this->prepareTurn($chosen);
        $this->lockGuild($guild);
        $input = $this->startChoice($sender, $guild);
        $blocked = $this->waitForLockOrFlush();

        $this->setTime(self::DEADLINE);
        $this->reveal();
        $sealed = $this->turnState($turn);
        $this->commitLock();
        $result = $this->finishChoice($input);

        self::assertSame($chosen ? 'SENT' : 'MISSED', $sealed['status']);
        self::assertSame($sealed, $this->turnState($turn), 'Un choix suspendu ne réécrit ni discipline ni chosen_at après scellement.');
        self::assertTrue($blocked, 'Le choix doit attendre le même verrou que la révélation.');
        self::assertSame(403, $result['status']);
        self::assertSame('https://grrind.app/problems/risala-turn-is-not-yours', $result['body']['type']);
        $this->assertOneNextTurn($guild);
        $this->reveal();
        $this->assertOneNextTurn($guild);
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function invalidations(): iterable
    {
        yield 'échéance exacte pendant le verrou' => ['deadline', 422, 'risala-turn-is-closed'];
        yield 'départ pendant le verrou' => ['leave', 404, 'guild-not-found'];
        yield 'dissolution pendant le verrou' => ['dissolve', 404, 'guild-not-found'];
    }

    #[DataProvider('invalidations')]
    public function testMembershipAndTimeAreReadAfterWaiting(string $action, int $status, string $problem): void
    {
        [$sender, $guild, $turn] = $this->prepareTurn();
        $this->lockGuild($guild);
        $input = $this->startChoice($sender, $guild);
        $blocked = $this->waitForLockOrFlush();
        $guilds = self::service(GuildRepository::class);
        if ('leave' === $action) {
            $guild->part($sender->id);
        } elseif ('dissolve' === $action) {
            $guilds->dissolve($guild);
        }
        $guilds->commit();
        $this->setTime('deadline' === $action ? self::DEADLINE : self::BEFORE);
        $this->commitLock();
        $result = $this->finishChoice($input);

        self::assertSame($status, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame('https://grrind.app/problems/'.$problem, $result['body']['type']);
        self::assertTrue($blocked);
        if ('dissolve' !== $action) {
            self::assertNull($this->turnState($turn)['discipline']);
            self::assertNull($this->turnState($turn)['chosen_at']);
        }
    }

    public function testAStillOpenTurnUsesTheTimeReadAfterTheLock(): void
    {
        [$sender, $guild, $turn] = $this->prepareTurn();
        $this->lockGuild($guild);
        $input = $this->startChoice($sender, $guild);
        $blocked = $this->waitForLockOrFlush();
        $this->setTime('2030-01-06T18:59:59+00:00');
        $this->commitLock();
        $result = $this->finishChoice($input);

        self::assertSame(200, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertTrue($blocked);
        $state = $this->turnState($turn);
        self::assertSame('DRAWN', $state['status']);
        self::assertSame('SWIMMING', $state['discipline']);
        self::assertIsString($state['chosen_at']);
        self::assertEquals(new DateTimeImmutable('2030-01-06T18:59:59+00:00'), new DateTimeImmutable($state['chosen_at']));
    }

    public function testAChoiceCommittedBeforeRevealSurvivesAnAlreadyManagedTurn(): void
    {
        [$sender, $guild, $turn] = $this->prepareTurn();
        $input = $this->startChoice($sender, $guild);
        $this->waitForOutput("before-flush\n");
        $result = $this->finishChoice($input);
        self::assertSame(200, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        $chosen = $this->turnState($turn);

        // Le kernel parent possède toujours le DRAWN sans choix, lu avant le COMMIT distant.
        $this->reveal();
        $sealed = $this->turnState($turn);
        self::assertSame('SENT', $sealed['status']);
        self::assertSame($chosen['discipline'], $sealed['discipline']);
        self::assertSame($chosen['chosen_at'], $sealed['chosen_at']);
        self::assertIsString($sealed['revealed_at']);
        self::assertEquals(new DateTimeImmutable(self::DEADLINE), new DateTimeImmutable($sealed['revealed_at']));
        $this->assertOneNextTurn($guild);
    }

    /** @return array{Account, Guild, Risala} */
    private function prepareTurn(bool $chosen = false): array
    {
        $sender = $this->openAccount();
        $other = $this->openAccount('other@grrind.app', 'Other');
        $guilds = self::service(GuildRepository::class);
        $guild = Guild::found('Frontière Risāla', $sender->id, new DateTimeImmutable(self::BEFORE));
        $guild->admit($other->id, new GuildRules(30, 48), new DateTimeImmutable(self::BEFORE));
        $guilds->add($guild);
        $turn = Risala::draw($guild, new RisalaRotation([$sender->id, $other->id], [], 0), 0, new DateTimeImmutable('2029-12-30T19:00:00+00:00'), new DateTimeImmutable(self::DEADLINE));
        if ($chosen) {
            $crediting = self::service(CreditingDisciplines::class);
            $turn->choose(Discipline::Climbing, $crediting, [], new DateTimeImmutable('2030-01-06T18:59:56+00:00'));
        }
        $risalat = self::service(RisalaRepository::class);
        $risalat->add($turn);
        $risalat->commit();
        $path = tempnam(\dirname(__DIR__, 2).'/var', 'risala-clock-');
        self::assertIsString($path);
        $this->clockPath = $path;
        $this->setTime(self::BEFORE);

        return [$sender, $guild, $turn];
    }

    private function lockGuild(Guild $guild): void
    {
        $connection = $this->lockedConnection = $this->connection();
        $connection->beginTransaction();
        $guilds = self::service(GuildRepository::class);
        self::assertNotNull($guilds->lockForUpdate($guild->id()));
    }

    private function startChoice(Account $sender, Guild $guild): InputStream
    {
        $input = new InputStream();
        $input->write(json_encode(['authorization' => $sender->headers['Authorization'], 'guildId' => $guild->id()->toRfc4122(), 'clockPath' => $this->clockPath], \JSON_THROW_ON_ERROR)."\n");
        $worker = $this->worker = new Process([\PHP_BINARY, \dirname(__DIR__).'/Support/choose_risala_worker.php'], \dirname(__DIR__, 2));
        $worker->setInput($input);
        $worker->setTimeout(20);
        $worker->start();
        $this->waitForOutput("ready\n");
        $input->write("continue\n");

        return $input;
    }

    // Avant correction, le choix atteint preFlush malgré le verrou : on le laisse finir
    // après la vraie révélation pour observer la corruption, plutôt qu'un simple timeout.
    private function waitForLockOrFlush(): bool
    {
        self::assertNotNull($this->worker);
        if (1 !== preg_match('/pid:(\d+)/', $this->worker->getOutput(), $matches)) {
            self::fail('Le choix doit annoncer son PID PostgreSQL.');
        }
        $connection = $this->connection();
        $pid = $connection->fetchOne('SELECT pg_backend_pid()');
        $deadline = microtime(true) + 10;
        do {
            if ($connection->fetchOne('SELECT ? = ANY(pg_blocking_pids(?))', [$pid, (int) $matches[1]])) {
                return true;
            }
            if (str_contains($this->worker->getOutput(), "before-flush\n")) {
                return false;
            }
            $this->worker->checkTimeout();
            usleep(10_000);
        } while ($this->worker->isRunning() && microtime(true) < $deadline);
        self::fail('Ni verrou ni barrière avant flush : '.$this->worker->getOutput().' '.$this->worker->getErrorOutput());
    }

    private function waitForOutput(string $expected): void
    {
        self::assertNotNull($this->worker);
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($this->worker->getOutput(), $expected)) {
                return;
            }
            $this->worker->checkTimeout();
            usleep(10_000);
        } while ($this->worker->isRunning() && microtime(true) < $deadline);
        self::fail('Barrière absente : '.$expected.' '.$this->worker->getOutput().' '.$this->worker->getErrorOutput());
    }

    private function commitLock(): void
    {
        self::assertNotNull($this->lockedConnection);
        self::assertSame(1, $this->lockedConnection->getTransactionNestingLevel(), 'Le handler conserve la transaction appelante.');
        $this->lockedConnection->commit();
    }

    /** @return array{status: int, body: array<mixed>} */
    private function finishChoice(InputStream $input): array
    {
        $input->write("flush\n");
        $input->close();
        self::assertNotNull($this->worker);
        self::assertSame(0, $this->worker->wait(), $this->worker->getErrorOutput());
        if (1 !== preg_match('/result:(.+)/', $this->worker->getOutput(), $matches)) {
            self::fail('Le choix doit annoncer sa réponse HTTP.');
        }
        $result = json_decode($matches[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsInt($result['status']);
        self::assertIsArray($result['body']);

        return ['status' => $result['status'], 'body' => $result['body']];
    }

    private function setTime(string $instant): void
    {
        self::assertNotNull($this->clockPath);
        file_put_contents($this->clockPath, $instant);
    }

    private function reveal(): void
    {
        $clock = self::service(MockClock::class);
        $clock->sleep(new DateTimeImmutable(self::DEADLINE)->getTimestamp() - $clock->now()->getTimestamp());
        $handler = self::service(RevealRisalatHandler::class);
        $handler(new RevealRisalat());
    }

    /** @return array<string, mixed> */
    private function turnState(Risala $turn): array
    {
        $row = $this->connection()->fetchAssociative('SELECT status, discipline, chosen_at, revealed_at, expires_at FROM community_risala WHERE id = ?', [$turn->id()->toRfc4122()]);
        self::assertIsArray($row);

        return $row;
    }

    private function assertOneNextTurn(Guild $guild): void
    {
        self::assertSame(2, $this->connection()->fetchOne('SELECT COUNT(*) FROM community_risala WHERE guild_id = ?', [$guild->id()->toRfc4122()]));
        self::assertSame(1, $this->connection()->fetchOne("SELECT COUNT(*) FROM community_risala WHERE guild_id = ? AND status = 'DRAWN'", [$guild->id()->toRfc4122()]));
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $service
     *
     * @return T
     */
    private static function service(string $service): object
    {
        $instance = self::getContainer()->get($service);
        self::assertInstanceOf($service, $instance);

        return $instance;
    }
}
