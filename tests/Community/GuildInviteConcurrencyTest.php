<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\IssueInviteCode;
use App\Community\Application\IssueInviteCodeHandler;
use App\Community\Application\RevokeInviteCode;
use App\Community\Application\RevokeInviteCodeHandler;
use App\Community\Domain\Guild;
use App\Community\Domain\GuildRules;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/** Deux connexions PostgreSQL, un vrai verrou bloquant et une horloge avancée pendant l'attente. */
final class GuildInviteConcurrencyTest extends ApiTestCase
{
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

    /** @return iterable<string, array{string}> */
    public static function invalidations(): iterable
    {
        yield 'révocation' => ['revoke'];
        yield 'réémission' => ['reissue'];
        yield 'expiration exacte pendant le verrou' => ['expire'];
    }

    #[DataProvider('invalidations')]
    public function testAnInvitationInvalidatedWhileWaitingAdmitsNobody(string $action): void
    {
        [$player, $guildId, $code] = $this->prepareGuild();
        [, $guild, $input] = $this->waitingJoin($player, $guildId, $code);
        $replacement = null;

        if ('revoke' === $action) {
            $handler = self::getContainer()->get(RevokeInviteCodeHandler::class);
            self::assertInstanceOf(RevokeInviteCodeHandler::class, $handler);
            $handler(new RevokeInviteCode($guild));
        } elseif ('reissue' === $action) {
            $handler = self::getContainer()->get(IssueInviteCodeHandler::class);
            self::assertInstanceOf(IssueInviteCodeHandler::class, $handler);
            $replacement = $handler(new IssueInviteCode($guild))->code();
        }

        // expires_at est fixé à cette date avant le démarrage du join, sans le modifier ici.
        $this->setTime('expire' === $action ? '2030-01-01T12:01:00+00:00' : '2030-01-01T12:00:30+00:00');
        $result = $this->finishJoin($input);

        self::assertSame(404, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->membershipCount($player));
        $unknown = $this->post('/api/guilds/join', ['code' => 'ZZZZZZZZ'], $player->headers);
        self::assertSame(self::decode($unknown), $result['body'], 'Même réponse que pour un code inconnu, sans donnée de guilde.');

        if (null !== $replacement) {
            self::assertSame(200, $this->post('/api/guilds/join', ['code' => $replacement], $player->headers)->getStatusCode());
        }
    }

    public function testAStillValidCodeAdmitsAtTheTimeReadAfterTheLock(): void
    {
        [$player, $guildId, $code] = $this->prepareGuild();
        [, , $input] = $this->waitingJoin($player, $guildId, $code);
        $this->setTime('2030-01-01T12:00:30+00:00');
        $result = $this->finishJoin($input);

        self::assertSame(200, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame($guildId, $result['body']['id']);
        self::assertSame(1, $this->membershipCount($player));
        $joinedAt = $this->connection()->fetchOne('SELECT joined_at FROM community_guild_membership WHERE player_id = ?', [$player->id->toRfc4122()]);
        self::assertIsString($joinedAt);
        self::assertEquals(new DateTimeImmutable('2030-01-01T12:00:30+00:00'), new DateTimeImmutable($joinedAt));
    }

    /** @return iterable<string, array{string, string}> */
    public static function conflicts(): iterable
    {
        yield 'double adhésion' => ['same-guild', 'player-already-in-a-guild'];
        yield 'autre guilde' => ['other-guild', 'player-already-in-a-guild'];
        yield 'dernière place prise' => ['capacity', 'guild-is-full'];
    }

    #[DataProvider('conflicts')]
    public function testCurrentMembershipAndCapacityKeepTheirBusinessRefusals(string $action, string $problem): void
    {
        [$player, $guildId, $code] = $this->prepareGuild();
        [$guilds, $guild, $input] = $this->waitingJoin($player, $guildId, $code);
        $now = new DateTimeImmutable();
        $rules = new GuildRules(30, 48);

        if ('same-guild' === $action) {
            $guild->admit($player->id, $rules, $now);
        } elseif ('other-guild' === $action) {
            $guilds->add(Guild::found('Autre guilde', $player->id, $now));
        } else {
            while ($guild->size() < 30) {
                $guild->admit(Uuid::v7(), $rules, $now);
            }
        }
        $guilds->commit();
        $result = $this->finishJoin($input);

        self::assertSame(409, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame('https://grrind.app/problems/'.$problem, $result['body']['type']);
        self::assertSame('capacity' === $action ? 0 : 1, $this->membershipCount($player));
        self::assertSame('capacity' === $action ? 30 : ('same-guild' === $action ? 2 : 1), $this->connection()->fetchOne('SELECT COUNT(*) FROM community_guild_membership WHERE guild_id = ?', [$guildId]));
    }

    /** @return array{Account, string, string} */
    private function prepareGuild(): array
    {
        $founder = $this->openAccount();
        $response = $this->post('/api/guilds', ['name' => 'Guilde privée'], $founder->headers);
        self::assertSame(201, $response->getStatusCode());
        $guildId = self::decode($response)['id'];
        self::assertIsString($guildId);
        $issued = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers);
        self::assertSame(201, $issued->getStatusCode());
        $code = self::decode($issued)['code'];
        self::assertIsString($code);
        $player = $this->openAccount('newcomer@grrind.app', 'Newcomer');
        $this->connection()->executeStatement('UPDATE community_guild_invite_code SET expires_at = ? WHERE code = ?', ['2030-01-01T12:01:00+00:00', $code]);
        $path = tempnam(\dirname(__DIR__, 2).'/var', 'join-clock-');
        self::assertIsString($path);
        $this->clockPath = $path;
        $this->setTime('2030-01-01T12:00:00+00:00');

        return [$player, $guildId, $code];
    }

    /** @return array{GuildRepository, Guild, InputStream} */
    private function waitingJoin(Account $player, string $guildId, string $code): array
    {
        $connection = $this->lockedConnection = $this->connection();
        $connection->beginTransaction();
        $guilds = self::getContainer()->get(GuildRepository::class);
        self::assertInstanceOf(GuildRepository::class, $guilds);
        $guild = $guilds->lockForUpdate(Uuid::fromString($guildId));
        self::assertInstanceOf(Guild::class, $guild);
        $pid = $connection->fetchOne('SELECT pg_backend_pid()');
        $input = new InputStream();
        $input->write(json_encode(['authorization' => $player->headers['Authorization'], 'code' => $code, 'clockPath' => $this->clockPath], \JSON_THROW_ON_ERROR)."\n");
        $worker = $this->worker = new Process([\PHP_BINARY, \dirname(__DIR__).'/Support/join_guild_worker.php'], \dirname(__DIR__, 2));
        $worker->setInput($input);
        $worker->setTimeout(20);
        $worker->start();
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($worker->getOutput(), "ready\n")) {
                break;
            }
            $worker->checkTimeout();
            usleep(10_000);
        } while ($worker->isRunning() && microtime(true) < $deadline);
        self::assertStringContainsString("ready\n", $worker->getOutput(), $worker->getErrorOutput());
        if (1 !== preg_match('/pid:(\d+)/', $worker->getOutput(), $matches)) {
            self::fail('Le join doit annoncer son PID PostgreSQL.');
        }
        $input->write("continue\n");
        $deadline = microtime(true) + 10;
        do {
            if ($connection->fetchOne('SELECT ? = ANY(pg_blocking_pids(?))', [$pid, (int) $matches[1]])) {
                return [$guilds, $guild, $input];
            }
            $worker->checkTimeout();
            usleep(10_000);
        } while ($worker->isRunning() && microtime(true) < $deadline);
        self::fail('Le join doit attendre le verrou de guilde : '.$worker->getOutput().' '.$worker->getErrorOutput());
    }

    /** @return array{status: int, body: array<mixed>} */
    private function finishJoin(InputStream $input): array
    {
        self::assertNotNull($this->lockedConnection);
        self::assertSame(1, $this->lockedConnection->getTransactionNestingLevel(), 'Les handlers conservent la transaction appelante.');
        $this->lockedConnection->commit();
        $input->close();
        self::assertNotNull($this->worker);
        self::assertSame(0, $this->worker->wait(), $this->worker->getErrorOutput());
        if (1 !== preg_match('/result:(.+)/', $this->worker->getOutput(), $matches)) {
            self::fail('Le join doit annoncer sa réponse HTTP.');
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

    private function membershipCount(Account $player): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM community_guild_membership WHERE player_id = ?', [$player->id->toRfc4122()]);
        self::assertIsInt($count);

        return $count;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
