<?php

declare(strict_types=1);

namespace App\Tests\Community;

use App\Community\Domain\AlamRun;
use App\Shared\Application\AlamContributions;
use App\Shared\Application\FrozenGameRulesets;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Alam\AlamCalendar;
use App\Shared\Domain\Alam\AlamRules;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final class AlamRoutesTest extends ApiTestCase
{
    protected function setUp(): void
    {
        $_ENV['ALAM_MANUAL_ENABLED'] = $_SERVER['ALAM_MANUAL_ENABLED'] = '1';
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_ENV['ALAM_MANUAL_ENABLED'] = $_SERVER['ALAM_MANUAL_ENABLED'] = '0';
        parent::tearDown();
    }

    public function testManualReplayAndWeeklyIsolationWithZeroContributors(): void
    {
        $bob = $this->openAccount();
        self::assertSame(404, $this->get('/api/guild/alam', $bob->headers)->getStatusCode());
        $this->post('/api/guilds', ['name' => 'La guilde'], $bob->headers);
        $current = self::decode($this->get('/api/guild/alam', $bob->headers));
        self::assertTrue($current['canLaunchManual']);
        self::assertIsArray($current['current']);
        self::assertSame(1, $current['current']['frozenTargetCount']);
        $weekly = $current['current']['id'];
        $headers = $bob->headers + ['Idempotency-Key' => 'first-manual'];
        $response = $this->post('/api/guild/alam/runs', [], $headers);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = self::decode($response);
        self::assertSame('MANUAL', $run['mode']);
        self::assertSame('RESOLVED', $run['status']);
        self::assertIsArray($run['encounters']);
        self::assertCount(3, $run['encounters']);
        foreach ($run['encounters'] as $encounter) {
            self::assertIsArray($encounter);
            self::assertFalse($encounter['won']);
            self::assertSame([], $encounter['drops']);
        }
        $replay = $this->post('/api/guild/alam/runs', [], $headers);
        self::assertSame($response->getContent(), $replay->getContent());
        $another = self::decode($this->post('/api/guild/alam/runs', [], $bob->headers + ['Idempotency-Key' => 'second-manual']));
        self::assertNotSame($run['id'], $another['id']);
        $current = self::decode($this->get('/api/guild/alam', $bob->headers));
        self::assertIsArray($current['current']);
        self::assertSame($weekly, $current['current']['id']);
        self::assertSame('COLLECTING', $current['current']['status']);
        $page = self::decode($this->get('/api/guild/alam/runs?limit=1', $bob->headers));
        self::assertIsArray($page['runs']);
        self::assertCount(1, $page['runs']);
        self::assertIsString($page['nextCursor']);
        self::assertSame(200, $this->get('/api/guild/alam/runs?cursor='.urlencode($page['nextCursor']), $bob->headers)->getStatusCode());
        $outsider = $this->openAccount('outsider@example.com');
        self::assertIsString($run['id']);
        self::assertSame(404, $this->get('/api/guild/alam/runs/'.$run['id'], $outsider->headers)->getStatusCode());
        self::assertSame(200, $this->get('/api/guild/alam/runs/'.$run['id'], $bob->headers)->getStatusCode());
    }

    public function testRealEffortGetsPartialRewardsAndLateImportCannotRewriteRun(): void
    {
        $bob = $this->openAccount();
        $this->post('/api/guilds', ['name' => 'La guilde'], $bob->headers);
        $rules = self::getContainer()->get(GameRulesets::class);
        $snapshot = $rules->snapshot();
        self::assertIsArray($snapshot['alam']);
        /** @var array<string, mixed> $alam */
        $alam = $snapshot['alam'];
        $week = new AlamCalendar(new AlamRules($alam))->week(new DateTimeImmutable());
        $start = $week['start']->modify('+10 minutes');
        $payload = ['workouts' => [['externalId' => 'alam-effort', 'activityType' => 'running', 'source' => 'APPLE_HEALTH', 'startedAt' => $start->format('c'), 'endedAt' => $start->modify('+60 minutes')->format('c'), 'distanceMeters' => 10000]]];
        $import = $this->post('/api/workouts/import', $payload, $bob->headers + ['Idempotency-Key' => 'import-alam']);
        self::assertSame(200, $import->getStatusCode(), (string) $import->getContent());
        $response = $this->post('/api/guild/alam/runs', [], $bob->headers + ['Idempotency-Key' => 'run-alam']);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = self::decode($response);
        self::assertIsArray($run['participants']);
        self::assertIsArray($run['participants'][0]);
        self::assertGreaterThan(0, $run['participants'][0]['contribution']);
        self::assertIsArray($run['encounters']);
        foreach ($run['encounters'] as $encounter) {
            self::assertIsArray($encounter);
            self::assertIsArray($encounter['drops']);
            self::assertNotEmpty($encounter['drops']);
            self::assertIsArray($encounter['drops'][0]);
            self::assertSame('NAFS_ESSENCE', $encounter['drops'][0]['itemKey']);
            self::assertGreaterThanOrEqual(1, $encounter['drops'][0]['quantity']);
        }
        self::assertIsString($run['id']);
        $stored = self::getContainer()->get(EntityManagerInterface::class)->getRepository(AlamRun::class)->find($run['id']);
        self::assertInstanceOf(AlamRun::class, $stored);
        $before = $stored->result;
        $payload['workouts'][0]['externalId'] = 'late-effort';
        $payload['workouts'][0]['startedAt'] = $start->modify('+2 hours')->format('c');
        $payload['workouts'][0]['endedAt'] = $start->modify('+3 hours')->format('c');
        $this->post('/api/workouts/import', $payload, $bob->headers + ['Idempotency-Key' => 'late-import']);
        $stored = self::getContainer()->get(EntityManagerInterface::class)->getRepository(AlamRun::class)->find($run['id']);
        self::assertInstanceOf(AlamRun::class, $stored);
        self::assertSame($before, $stored->result);
        $frozen = new FrozenGameRulesets($snapshot, 'test');
        $contributions = self::getContainer()->get(AlamContributions::class)->between([$bob->id], $week['start'], $week['close'], $frozen);
        self::assertGreaterThan($run['participants'][0]['contribution'], $contributions[$bob->id->toRfc4122()]['total']);
    }

    public function testCommittedRunSurvivesLostHttpReceiptAndParticipantDeparture(): void
    {
        $bob = $this->openAccount();
        $this->post('/api/guilds', ['name' => 'La guilde'], $bob->headers);
        $headers = $bob->headers + ['Idempotency-Key' => 'lost-receipt'];
        $run = self::decode($this->post('/api/guild/alam/runs', [], $headers));
        self::assertIsString($run['id']);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement('DELETE FROM shared_idempotency_key');
        $retried = self::decode($this->post('/api/guild/alam/runs', [], $headers));
        self::assertSame($run['id'], $retried['id']);
        self::assertSame($run['events'], $retried['events']);
        self::assertSame(204, $this->post('/api/guilds/mine/leave', [], $bob->headers)->getStatusCode());
        self::assertSame(200, $this->get('/api/guild/alam/runs/'.$run['id'], $bob->headers)->getStatusCode());
    }

    public function testSchedulerCatchesUpAndDoesNotResolveTwice(): void
    {
        $bob = $this->openAccount();
        $guild = self::decode($this->post('/api/guilds', ['name' => 'La guilde'], $bob->headers));
        self::assertIsString($guild['id']);
        $rules = self::getContainer()->get(GameRulesets::class);
        $now = new DateTimeImmutable();
        $run = new AlamRun(\Symfony\Component\Uid\Uuid::fromString($guild['id']), \App\Community\Domain\AlamMode::Weekly, $now->modify('-8 days'), $now->modify('-1 day'), $now->modify('-8 days'), 1, $rules->version(), $rules->snapshot());
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist($run);
        $em->flush();
        $service = self::getContainer()->get(\App\Community\Application\AlamRuns::class);
        $service->tick();
        self::assertNotNull($run->resolvedAt);
        $result = $run->result;
        $service->tick();
        self::assertSame($result, $run->result);
        self::assertSame(1, $em->getConnection()->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE body LIKE '%NarrateAlam%' OR headers LIKE '%NarrateAlam%'"));
    }

    public function testManualFlagAndAuthenticationAreEnforced(): void
    {
        self::assertSame(401, $this->get('/api/guild/alam')->getStatusCode());
        $bob = $this->openAccount();
        $this->post('/api/guilds', ['name' => 'La guilde'], $bob->headers);
        $_ENV['ALAM_MANUAL_ENABLED'] = $_SERVER['ALAM_MANUAL_ENABLED'] = '0';
        $response = $this->post('/api/guild/alam/runs', [], $bob->headers + ['Idempotency-Key' => 'disabled']);
        self::assertSame(403, $response->getStatusCode(), (string) $response->getContent());
    }
}
