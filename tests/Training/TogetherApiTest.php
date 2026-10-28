<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\TrustLevel;
use App\Shared\Domain\Activity\WorkoutSource;
use App\Shared\Domain\Event\WorkoutImported;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use App\Training\Application\MatchTogetherOnImport;
use App\Training\Application\TogetherTracker;
use Symfony\Component\Uid\Uuid;

final class TogetherApiTest extends ApiTestCase
{
    use Workouts;

    public function testLatePlayersRetriesAndCapKeepTheOriginalImportResponse(): void
    {
        $players = [];
        for ($i = 0; $i < 5; ++$i) {
            $players[] = $this->openAccount('player'.$i.'@grrind.app', 'Player'.$i);
        }
        $response = $this->import($players[0]);
        $initial = (string) $response->getContent();
        $this->evidence($players[0]);
        for ($i = 1; $i < 5; ++$i) {
            $this->import($players[$i]);
            $this->evidence($players[$i]);
        }
        foreach ($players as $player) {
            self::assertSame(120, $this->number('SELECT SUM(amount) FROM xp_transaction WHERE user_id = ?', [$player->id->toRfc4122()]));
            /** @var array{workouts: list<array{together: array{multiplier: int, bonusXp: int}}> } $history */
            $history = self::decode($this->get('/api/workouts', $player->headers));
            self::assertSame(4, $history['workouts'][0]['together']['multiplier']);
            self::assertSame(90, $history['workouts'][0]['together']['bonusXp']);
            self::assertSame(1, $this->number("SELECT COUNT(*) FROM xp_transaction WHERE user_id = ? AND reason = 'SESSION_COMPLETED'", [$player->id->toRfc4122()]));
        }
        $this->evidence($players[0]);
        self::assertSame($initial, $this->import($players[0])->getContent());
        /** @var array{bonuses: list<array{bonusXp: int}>, hasMore: bool, nextCursor: string} $feed */
        $feed = self::decode($this->get('/api/workouts/together-bonuses?limit=1', $players[0]->headers));
        self::assertCount(1, $feed['bonuses']);
        self::assertSame(30, $feed['bonuses'][0]['bonusXp']);
        self::assertTrue($feed['hasMore']);
        /** @var array{bonuses: list<array<string, mixed>>} $rest */
        $rest = self::decode($this->get('/api/workouts/together-bonuses?after='.$feed['nextCursor'], $players[0]->headers));
        self::assertCount(2, $rest['bonuses']);
        self::assertSame([], self::decode($this->get('/api/workouts/together-bonuses', $this->openAccount('outsider@grrind.app')->headers))['bonuses']);
        self::assertSame(30, $this->number("SELECT SUM(amount) FROM xp_transaction WHERE user_id = ? AND reason != 'TOGETHER_BONUS'", [$players[0]->id->toRfc4122()]));
    }

    public function testEvidenceBeforeImportAndTheAsynchronousImportHandler(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        $pending = $this->evidence($a);
        self::assertNull(self::decode($pending)['workoutId']);
        $this->import($b);
        $this->evidence($b);
        /** @var array{imported: list<array{session: array{id: string}}> } $import */
        $import = self::decode($this->import($a));
        $id = Uuid::fromString($import['imported'][0]['session']['id']);
        $handler = self::getContainer()->get(MatchTogetherOnImport::class);
        self::assertInstanceOf(MatchTogetherOnImport::class, $handler);
        $handler(new WorkoutImported($id, $a->id, Discipline::Running, self::daysAgo(3), self::daysAgo(3)->modify('+30 minutes'), 1800, WorkoutSource::AppleHealth, TrustLevel::ProviderVerified));
        $handler(new WorkoutImported($id, $a->id, Discipline::Running, self::daysAgo(3), self::daysAgo(3)->modify('+30 minutes'), 1800, WorkoutSource::AppleHealth, TrustLevel::ProviderVerified));
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM together_bonus_receipt'));
        self::assertSame(120, $this->number('SELECT SUM(amount) FROM xp_transaction'));
    }

    public function testDifferentSportsAndPoorLocationsRemainUnverified(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        $this->import($a);
        $this->import($b, 'cycling');
        $this->evidence($a);
        $this->evidence($b);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM together_bonus_receipt'));
        /** @var array{workouts: list<array{together: array{status: string, multiplier: int}}> } $body */
        $body = self::decode($this->get('/api/workouts', $a->headers));
        $status = $body['workouts'][0]['together'];
        self::assertSame('INSUFFICIENT_EVIDENCE', $status['status']);
        self::assertSame(1, $status['multiplier']);
    }

    public function testBoundaryValidationOwnershipAndBatchConflict(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        $payload = $this->payload();
        self::assertSame(401, $this->post('/api/workouts/location-evidence', $payload)->getStatusCode());
        $this->post('/api/workouts/location-evidence', $payload, $a->headers);
        $payload['points'][0]['latitude'] = 49.0;
        self::assertSame(409, $this->post('/api/workouts/location-evidence', $payload, $a->headers)->getStatusCode());
        self::assertSame(200, $this->post('/api/workouts/location-evidence', $payload, $b->headers)->getStatusCode());
        $payload = $this->payload();
        $payload['points'][0]['latitude'] = 91;
        self::assertSame(422, $this->post('/api/workouts/location-evidence', $payload, $a->headers)->getStatusCode());
        $payload = $this->payload();
        $payload['points'][0]['timestamp'] = self::daysAgo(5)->format(\DATE_ATOM);
        self::assertSame(422, $this->post('/api/workouts/location-evidence', $payload, $a->headers)->getStatusCode());
        self::assertSame(400, $this->get('/api/workouts/together-bonuses?limit=101', $a->headers)->getStatusCode());
    }

    public function testPurgeDoesNotRemoveTheLogicalProofOrLedger(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        foreach ([$a, $b] as $player) {
            $this->import($player);
            $this->evidence($player);
        }
        $this->connection()->executeStatement("UPDATE location_evidence SET ended_at = NOW() - INTERVAL '31 days'");
        $tracker = self::getContainer()->get(TogetherTracker::class);
        self::assertInstanceOf(TogetherTracker::class, $tracker);
        self::assertSame(2, $tracker->purge());
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM together_bonus_receipt'));
        self::assertNotSame('{}', $this->connection()->fetchOne('SELECT together_proof FROM workout LIMIT 1'));
    }

    public function testGroupXpDoesNotConsumeTheDailyBudgetOrReapplyCurrentModifiers(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        $this->import($a);
        $this->import($b);
        \App\Tests\Support\ProgrammableModifiers::grant(new \App\Shared\Domain\Modifier\Modifier(\App\Shared\Domain\Modifier\ModifierType::XpMultiplier, 10000, \App\Shared\Domain\Modifier\ModifierSource::Skill));
        $this->evidence($a);
        $this->evidence($b);
        self::assertSame(60, $this->number('SELECT SUM(amount) FROM xp_transaction WHERE user_id = ?', [$a->id->toRfc4122()]));
        $load = self::getContainer()->get(\App\Progression\Application\DailyLoadProvider::class);
        self::assertInstanceOf(\App\Progression\Application\DailyLoadProvider::class, $load);
        $day = $load->of($a->id, Discipline::Running, self::daysAgo(3));
        self::assertSame(30, $day->xpSoFarInDiscipline);
        self::assertSame(1800, $day->secondsSoFar);
    }

    public function testWalkingStillGrantsNoXpAndOldProofsAreRejected(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        foreach ([$a, $b] as $player) {
            $this->import($player, 'walking');
            $this->evidence($player);
        }
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM xp_transaction'));
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM together_bonus_receipt'));
        $expired = $this->payload();
        $expired['startedAt'] = self::daysAgo(35)->format(\DATE_ATOM);
        $expired['endedAt'] = self::daysAgo(35)->modify('+30 minutes')->format(\DATE_ATOM);
        self::assertSame(410, $this->post('/api/workouts/location-evidence', $expired, $a->headers)->getStatusCode());
    }

    public function testProvidersShareTheServerNormalizedSport(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        $this->import($a);
        $start = self::daysAgo(3);
        $response = $this->post('/api/workouts/import', ['workouts' => [['externalId' => 'health-workout', 'source' => 'HEALTH_CONNECT', 'activityType' => 'EXERCISE_TYPE_RUNNING', 'startedAt' => $start->format(\DATE_ATOM), 'endedAt' => $start->modify('+30 minutes')->format(\DATE_ATOM)]]], $b->headers + ['Idempotency-Key' => 'android-import']);
        self::assertSame(200, $response->getStatusCode());
        $this->evidence($a);
        $proof = $this->payload();
        $proof['source'] = 'HEALTH_CONNECT';
        $response = $this->post('/api/workouts/location-evidence', $proof, $b->headers);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM together_bonus_receipt'));
    }

    public function testConcurrentEvidenceAndRetriesCannotDoubleTheCredit(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        $this->import($a);
        $this->import($b);
        $code = <<<'PHP'
            require 'tests/bootstrap.php';
            $kernel = new App\Kernel('test', true);
            $request = Symfony\Component\HttpFoundation\Request::create('/api/workouts/location-evidence', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => $argv[1]], content: $argv[2]);
            $response = $kernel->handle($request);
            echo $response->getStatusCode();
            $kernel->terminate($request, $response);
            PHP;
        $processes = [];
        foreach ([$a, $b, $a, $b] as $player) {
            $process = new \Symfony\Component\Process\Process([\PHP_BINARY, '-r', $code, $player->headers['Authorization'], json_encode($this->payload(), \JSON_THROW_ON_ERROR)], \dirname(__DIR__, 2));
            $process->start();
            $processes[] = $process;
        }
        foreach ($processes as $process) {
            self::assertSame(0, $process->wait());
            self::assertSame('200', $process->getOutput(), $process->getErrorOutput());
        }
        self::assertSame(2, $this->number('SELECT COUNT(*) FROM together_bonus_receipt'));
        self::assertSame(120, $this->number('SELECT SUM(amount) FROM xp_transaction'));
    }

    public function testTheFullPostCapAmountIsMultiplied(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        /** @var array{imported: list<array{xp: array{awarded: int, breakdown: list<array{source: string}>}}> } $initial */
        $initial = self::decode($this->import($a, distanceMeters: 1000000));
        $xp = $initial['imported'][0]['xp'];
        self::assertContains('DAILY_CAP', array_column($xp['breakdown'], 'source'));
        $this->import($b);
        $this->evidence($a);
        $this->evidence($b);
        self::assertSame($xp['awarded'] * 2, $this->number('SELECT SUM(amount) FROM xp_transaction WHERE user_id = ?', [$a->id->toRfc4122()]));
    }

    public function testAnInvalidatedWorkoutCannotCountForItsBuddy(): void
    {
        $a = $this->openAccount();
        $b = $this->openAccount('alice@grrind.app', 'Alice');
        $this->import($a);
        $this->import($b);
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $em);
        $credit = $em->getRepository(\App\Progression\Domain\XpTransaction::class)->findOneBy(['userId' => $a->id, 'reason' => \App\Progression\Domain\XpReason::SessionCompleted]);
        self::assertInstanceOf(\App\Progression\Domain\XpTransaction::class, $credit);
        $em->persist(\App\Progression\Domain\XpTransaction::reversalOf($credit));
        $em->flush();
        $this->evidence($a);
        $this->evidence($b);
        self::assertSame(0, $this->number('SELECT COUNT(*) FROM together_bonus_receipt'));
        self::assertSame(30, $this->number('SELECT SUM(amount) FROM xp_transaction'));
    }

    /** @param list<string> $parameters */
    private function number(string $sql, array $parameters = []): int
    {
        $value = $this->connection()->fetchOne($sql, $parameters);
        \assert(is_numeric($value));

        return (int) $value;
    }

    private function import(Account $player, string $sport = 'running', ?int $distanceMeters = null): \Symfony\Component\HttpFoundation\Response
    {
        $start = self::daysAgo(3);
        $response = $this->post('/api/workouts/import', ['workouts' => [['externalId' => 'health-workout', 'source' => 'APPLE_HEALTH', 'activityType' => $sport, 'startedAt' => $start->format(\DATE_ATOM), 'endedAt' => $start->modify('+30 minutes')->format(\DATE_ATOM), 'distanceMeters' => $distanceMeters]]], $player->headers + ['Idempotency-Key' => 'initial-import']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    private function evidence(Account $player): \Symfony\Component\HttpFoundation\Response
    {
        $response = $this->post('/api/workouts/location-evidence', $this->payload(), $player->headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    /** @return array{batchId: string, source: string, externalId: string, origin: string, startedAt: string, endedAt: string, points: list<array{timestamp: string, latitude: float|int, longitude: float, horizontalAccuracy: float}>} */
    private function payload(): array
    {
        $start = self::daysAgo(3);
        $points = [];
        for ($seconds = 0; $seconds <= 1800; $seconds += 15) {
            $points[] = ['timestamp' => $start->modify('+'.$seconds.' seconds')->format(\DATE_ATOM), 'latitude' => 48.0, 'longitude' => 2.0, 'horizontalAccuracy' => 5.0];
        }

        return ['batchId' => '00000000-0000-7000-8000-000000000329', 'source' => 'APPLE_HEALTH', 'externalId' => 'health-workout', 'origin' => 'PHONE_CAPTURE', 'startedAt' => $start->format(\DATE_ATOM), 'endedAt' => $start->modify('+30 minutes')->format(\DATE_ATOM), 'points' => $points];
    }
}
