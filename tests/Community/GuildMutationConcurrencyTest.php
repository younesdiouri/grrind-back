<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Application\FoundGuild;
use App\Community\Application\FoundGuildHandler;
use App\Community\Application\GuildRecruitment;
use App\Community\Application\LeaveGuild;
use App\Community\Application\LeaveGuildHandler;
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

/** Deux kernels/connexions PostgreSQL ; la mutation attend après le vrai voter HTTP. */
final class GuildMutationConcurrencyTest extends ApiTestCase
{
    private ?Process $worker = null;
    private ?Connection $lockedConnection = null;

    protected function tearDown(): void
    {
        $this->worker?->stop(0);
        if ($this->lockedConnection?->isTransactionActive()) {
            $this->lockedConnection->rollBack();
        }
        parent::tearDown();
    }

    public function testConcurrentSubmissionsReturnOnePendingApplication(): void
    {
        [$founder, , $guildId] = $this->prepareGuild();
        $uri = '/api/guilds/'.$guildId;
        self::assertSame(200, $this->send('PATCH', $uri, ['recruitmentOpen' => true], $founder->headers)->getStatusCode());
        $player = $this->openAccount('recruit@example.org', 'Candidate');
        $input = $this->authorizedMutation($player, 'POST', $uri.'/applications', []);
        $this->lockGuild($guildId);
        $this->resumeUntilBlocked($input);
        $service = self::getContainer()->get(GuildRecruitment::class);
        self::assertInstanceOf(GuildRecruitment::class, $service);
        $first = $service->submit(Uuid::fromString($guildId), $player->id);
        $result = $this->finishMutation($input);
        self::assertSame(200, $result['status']);
        self::assertSame($first->id->toRfc4122(), $result['body']['id']);
        self::assertSame(1, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild_application'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function recruitmentConflicts(): iterable
    {
        yield 'dernière place prise' => ['full', 409];
        yield 'fondation ailleurs' => ['found', 409];
        yield 'transmission du rôle' => ['succession', 404];
        yield 'rejet déjà enregistré' => ['reject', 409];
    }

    #[DataProvider('recruitmentConflicts')]
    public function testAcceptanceRechecksChangesCommittedWhileWaiting(string $change, int $status): void
    {
        [$founder, , $guildId] = $this->prepareGuild();
        $uri = '/api/guilds/'.$guildId;
        self::assertSame(200, $this->send('PATCH', $uri, ['recruitmentOpen' => true], $founder->headers)->getStatusCode());
        $player = $this->openAccount('recruit@example.org', 'Candidate');
        $application = self::decode($this->post($uri.'/applications', [], $player->headers + ['Idempotency-Key' => 'apply']))['id'];
        self::assertIsString($application);
        $input = $this->authorizedMutation($founder, 'POST', $uri.'/applications/'.$application.'/accept', []);
        [$guilds, $guild] = $this->lockGuild($guildId);
        $this->resumeUntilBlocked($input);
        if ('full' === $change) {
            while ($guild->size() < 30) {
                $guild->admit(Uuid::v7(), new GuildRules(30, 48), new DateTimeImmutable());
            }
            $guilds->commit();
        } elseif ('found' === $change) {
            $found = self::getContainer()->get(FoundGuildHandler::class);
            self::assertInstanceOf(FoundGuildHandler::class, $found);
            $found(new FoundGuild($player->id, 'Autre guilde'));
        } elseif ('succession' === $change) {
            $leave = self::getContainer()->get(LeaveGuildHandler::class);
            self::assertInstanceOf(LeaveGuildHandler::class, $leave);
            $leave(new LeaveGuild($founder->id));
        } else {
            $service = self::getContainer()->get(GuildRecruitment::class);
            self::assertInstanceOf(GuildRecruitment::class, $service);
            $service->decide($guild->id(), $founder->id, Uuid::fromString($application), false);
        }
        $result = $this->finishMutation($input);
        self::assertSame($status, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame(0, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild_membership WHERE guild_id = ? AND player_id = ?', [$guildId, $player->id->toRfc4122()]));
        self::assertSame(match ($change) {
            'found' => 'WITHDRAWN',
            'reject' => 'REJECTED',
            default => 'PENDING',
        }, $this->db()->fetchOne('SELECT status FROM community_guild_application WHERE id = ?', [$application]));
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, int}> */
    public static function mutations(): iterable
    {
        yield 'dissoudre' => ['DELETE', '', [], 204];
        yield 'modifier l’identité' => ['PATCH', '', ['name' => 'Nouveau nom', 'emblem' => ['symbol' => 'BOLT', 'backgroundColor' => 'NIGHT', 'symbolColor' => 'COPPER']], 200];
        yield 'émettre' => ['POST', '/invite-code', [], 201];
        yield 'révoquer' => ['DELETE', '/invite-code', [], 204];
        yield 'exclure' => ['DELETE', '/members/HEIR', [], 204];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('mutations')]
    public function testADepartedFounderCannotMutateAfterPassingTheVoter(string $method, string $suffix, array $payload, int $status): void
    {
        $this->assertFormerFounderRefused($method, $suffix, $payload, rejoin: false);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('mutations')]
    public function testAFormerFounderWhoRejoinedIsForbiddenAfterPassingTheVoter(string $method, string $suffix, array $payload, int $status): void
    {
        $this->assertFormerFounderRefused($method, $suffix, $payload, rejoin: true);
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('mutations')]
    public function testTheCurrentFounderKeepsTheMutationAfterWaiting(string $method, string $suffix, array $payload, int $status): void
    {
        [$founder, $heir, $guildId] = $this->prepareGuild();
        $input = $this->authorizedMutation($founder, $method, $this->uri($guildId, $suffix, $heir), $payload);
        $this->lockGuild($guildId);
        $this->resumeUntilBlocked($input);
        $result = $this->finishMutation($input);

        self::assertSame($status, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        if ('PATCH' === $method) {
            self::assertSame('Nouveau nom', $this->db()->fetchOne('SELECT name FROM community_guild WHERE id = ?', [$guildId]));
            self::assertSame('BOLT', $this->db()->fetchOne('SELECT emblem_symbol FROM community_guild WHERE id = ?', [$guildId]));
        } elseif ('' === $suffix) {
            self::assertSame(0, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild WHERE id = ?', [$guildId]));
        } elseif (str_starts_with($suffix, '/members/')) {
            self::assertSame(0, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild_membership WHERE player_id = ?', [$heir->id->toRfc4122()]));
        } else {
            self::assertSame('POST' === $method ? 1 : 0, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild_invite_code WHERE guild_id = ? AND revoked_at IS NULL', [$guildId]));
        }
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('mutations')]
    public function testAGuildDissolvedAfterTheVoterStaysNotFound(string $method, string $suffix, array $payload, int $status): void
    {
        [$founder, $heir, $guildId] = $this->prepareGuild();
        $input = $this->authorizedMutation($founder, $method, $this->uri($guildId, $suffix, $heir), $payload);
        [$guilds, $guild] = $this->lockGuild($guildId);
        $guilds->dissolve($guild);
        $guilds->commit();
        $this->resumeUntilBlocked($input);
        $result = $this->finishMutation($input);

        self::assertSame(404, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame('https://grrind.app/problems/guild-not-found', $result['body']['type']);
        self::assertSame(0, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild'));
        self::assertSame(0, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild_invite_code'));
    }

    public function testLockReloadsTheSuccessorRoleAlreadyPresentInTheIdentityMap(): void
    {
        [$founder, $heir, $guildId] = $this->prepareGuild();
        $input = $this->authorizedMutation($founder, 'POST', '/api/guilds/mine/leave', []);
        $guilds = self::getContainer()->get(GuildRepository::class);
        self::assertInstanceOf(GuildRepository::class, $guilds);
        $stale = $guilds->ofId(Uuid::fromString($guildId));
        self::assertInstanceOf(Guild::class, $stale);
        self::assertFalse($stale->isFoundedBy($heir->id));
        $input->write("continue\n");
        self::assertSame(204, $this->workerResult()['status']);
        $input->close();

        $guilds->transactional(static function () use ($guilds, $guildId, $heir, $founder): void {
            $fresh = $guilds->lockForUpdate(Uuid::fromString($guildId));
            self::assertInstanceOf(Guild::class, $fresh);
            self::assertTrue($fresh->isFoundedBy($heir->id), 'Relire la collection ne suffit pas : son membre est déjà managé avec MEMBER.');
            self::assertFalse($fresh->hasMember($founder->id));
        });
    }

    /** @param array<string, mixed> $payload */
    private function assertFormerFounderRefused(string $method, string $suffix, array $payload, bool $rejoin): void
    {
        [$founder, $heir, $guildId] = $this->prepareGuild();
        $input = $this->authorizedMutation($founder, $method, $this->uri($guildId, $suffix, $heir), $payload);
        [$guilds, $guild] = $this->lockGuild($guildId);
        $leave = self::getContainer()->get(LeaveGuildHandler::class);
        self::assertInstanceOf(LeaveGuildHandler::class, $leave);
        $leave(new LeaveGuild($founder->id));
        if ($rejoin) {
            $guild->admit($founder->id, new GuildRules(30, 48), new DateTimeImmutable());
            $guilds->commit();
        }
        $this->resumeUntilBlocked($input);
        $result = $this->finishMutation($input);

        self::assertSame($rejoin ? 403 : 404, $result['status'], json_encode($result, \JSON_THROW_ON_ERROR));
        self::assertSame('https://grrind.app/problems/'.($rejoin ? 'forbidden' : 'guild-not-found'), $result['body']['type']);
        self::assertSame('Guilde privée', $this->db()->fetchOne('SELECT name FROM community_guild WHERE id = ?', [$guildId]));
        self::assertSame('DUMBBELL', $this->db()->fetchOne('SELECT emblem_symbol FROM community_guild WHERE id = ?', [$guildId]));
        self::assertSame('FOUNDER', $this->db()->fetchOne('SELECT role FROM community_guild_membership WHERE player_id = ?', [$heir->id->toRfc4122()]));
        self::assertSame($rejoin ? 2 : 1, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild_membership WHERE guild_id = ?', [$guildId]));
        self::assertSame(1, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild_invite_code WHERE guild_id = ? AND revoked_at IS NULL', [$guildId]));
        self::assertSame(1, $this->db()->fetchOne('SELECT COUNT(*) FROM community_guild_invite_code WHERE guild_id = ?', [$guildId]));

        // Le successeur garde une action réelle ; created_by désigne toujours le fondateur initial.
        self::assertSame(200, $this->send('PATCH', '/api/guilds/'.$guildId, ['name' => 'La succession fonctionne'], $heir->headers)->getStatusCode());
    }

    /** @return array{Account, Account, string} */
    private function prepareGuild(): array
    {
        $founder = $this->openAccount();
        $heir = $this->openAccount('heir@grrind.app', 'Héritière');
        $response = $this->post('/api/guilds', ['name' => 'Guilde privée'], $founder->headers);
        self::assertSame(201, $response->getStatusCode());
        $guildId = self::decode($response)['id'];
        self::assertIsString($guildId);
        $issued = $this->post('/api/guilds/'.$guildId.'/invite-code', [], $founder->headers);
        self::assertSame(201, $issued->getStatusCode());
        self::assertSame(200, $this->post('/api/guilds/join', ['code' => self::decode($issued)['code']], $heir->headers)->getStatusCode());

        return [$founder, $heir, $guildId];
    }

    private function uri(string $guildId, string $suffix, Account $heir): string
    {
        return '/api/guilds/'.$guildId.str_replace('HEIR', $heir->id->toRfc4122(), $suffix);
    }

    /** @param array<string, mixed> $payload */
    private function authorizedMutation(Account $actor, string $method, string $uri, array $payload): InputStream
    {
        $input = new InputStream();
        $input->write(json_encode(['authorization' => $actor->headers['Authorization'], 'method' => $method, 'uri' => $uri, 'payload' => $payload], \JSON_THROW_ON_ERROR)."\n");
        $this->worker = new Process([\PHP_BINARY, \dirname(__DIR__).'/Support/guild_mutation_worker.php'], \dirname(__DIR__, 2));
        $this->worker->setInput($input);
        $this->worker->setTimeout(20);
        $this->worker->start();
        $deadline = microtime(true) + 10;
        do {
            if (str_contains($this->worker->getOutput(), "authorized\n")) {
                return $input;
            }
            $this->worker->checkTimeout();
            usleep(10_000);
        } while ($this->worker->isRunning() && microtime(true) < $deadline);
        self::fail('La requête doit passer le voter : '.$this->worker->getOutput().' '.$this->worker->getErrorOutput());
    }

    /** @return array{GuildRepository, Guild} */
    private function lockGuild(string $guildId): array
    {
        $this->lockedConnection = $this->db();
        $this->lockedConnection->beginTransaction();
        $guilds = self::getContainer()->get(GuildRepository::class);
        self::assertInstanceOf(GuildRepository::class, $guilds);
        $guild = $guilds->lockForUpdate(Uuid::fromString($guildId));
        self::assertInstanceOf(Guild::class, $guild);

        return [$guilds, $guild];
    }

    private function resumeUntilBlocked(InputStream $input): void
    {
        self::assertNotNull($this->worker);
        if (1 !== preg_match('/pid:(\d+)/', $this->worker->getOutput(), $pid)) {
            self::fail('La mutation doit annoncer son PID PostgreSQL.');
        }
        $blocker = $this->db()->fetchOne('SELECT pg_backend_pid()');
        $input->write("continue\n");
        $deadline = microtime(true) + 10;
        do {
            if ($this->db()->fetchOne('SELECT ? = ANY(pg_blocking_pids(?))', [$blocker, (int) $pid[1]])) {
                return;
            }
            $this->worker->checkTimeout();
            usleep(10_000);
        } while ($this->worker->isRunning() && microtime(true) < $deadline);
        self::fail('La mutation doit attendre PostgreSQL : '.$this->worker->getOutput().' '.$this->worker->getErrorOutput());
    }

    /** @return array{status: int, body: array<mixed>} */
    private function finishMutation(InputStream $input): array
    {
        self::assertNotNull($this->lockedConnection);
        self::assertSame(1, $this->lockedConnection->getTransactionNestingLevel());
        $this->lockedConnection->commit();
        $input->close();

        return $this->workerResult();
    }

    /** @return array{status: int, body: array<mixed>} */
    private function workerResult(): array
    {
        self::assertNotNull($this->worker);
        self::assertSame(0, $this->worker->wait(), $this->worker->getErrorOutput());
        if (1 !== preg_match('/result:(.+)/', $this->worker->getOutput(), $matches)) {
            self::fail('La mutation doit annoncer sa réponse HTTP.');
        }
        $result = json_decode($matches[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($result);
        self::assertIsInt($result['status']);
        self::assertTrue(null === $result['body'] || \is_array($result['body']));

        return ['status' => $result['status'], 'body' => $result['body'] ?? []];
    }

    private function db(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
