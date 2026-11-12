<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * @phpstan-type Totals array{seconds: int, sessions: int, activeDays: int, averageSeconds: int|float}
 * @phpstan-type Stats array{period: string, timezone: string, start: string, end: string, observedEnd: string, previousStart: string, previousEnd: string, days: int, current: Totals, previous: Totals, change: ?int, grade: ?string, daily: list<array{date: string, seconds: int, sessions: int}>, buckets: list<array{from: string, to: string, seconds: int, sessions: int, activeDays: int, isFuture: bool}>, disciplines: list<array{discipline: string, seconds: int, sessions: int, share: float}>}
 */
final class WorkoutStatsTest extends ApiTestCase
{
    use Workouts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
    }

    public function testWeekAndMonthReallyUseDifferentVolumesAndReferences(): void
    {
        $bob = $this->openAccount();
        $alice = $this->openAccount('alice@grrind.app', 'Alice');
        $this->at('2026-07-15T12:00:00Z');
        // Deux UUID au même instant comptent deux fois ; pas de dédoublonnage par date.
        $this->workout($bob, '2026-07-12T22:00:00Z', 1800, externalId: 'same-time-a');
        $this->workout($bob, '2026-07-12T22:00:00Z', 900, externalId: 'same-time-b');
        // Traverse minuit : la durée entière reste au mardi, jour du début.
        $this->workout($bob, '2026-07-14T21:45:00Z', 1800, 'CYCLING');
        // Après l'heure courante mais dans le jour observé : référence de l'app conservée.
        $this->workout($bob, '2026-07-15T19:00:00Z', 1200);
        $this->workout($bob, '2026-07-06T07:00:00Z', 1800);
        $this->workout($bob, '2026-07-08T07:00:00Z', 1200, 'CYCLING');
        $this->workout($bob, '2026-07-09T07:00:00Z', 6000);
        $this->workout($bob, '2026-07-01T07:00:00Z', 600);
        $this->workout($bob, '2026-06-01T07:00:00Z', 4000);
        $this->workout($bob, '2026-06-15T07:00:00Z', 1000);
        $this->workout($bob, '2026-06-16T07:00:00Z', 9000);
        $this->workout($bob, '2026-07-16T07:00:00Z', 7200);
        $this->workout($alice, '2026-07-13T07:00:00Z', 18000);

        $week = $this->stats($bob);
        self::assertSame('week', $week['period']);
        self::assertSame('Europe/Paris', $week['timezone']);
        self::assertSame('2026-07-13T00:00:00+02:00', $week['start']);
        self::assertSame('2026-07-20T00:00:00+02:00', $week['end']);
        self::assertSame('2026-07-16T00:00:00+02:00', $week['observedEnd']);
        self::assertSame('2026-07-06T00:00:00+02:00', $week['previousStart']);
        self::assertSame('2026-07-09T00:00:00+02:00', $week['previousEnd']);
        self::assertSame(3, $week['days']);
        self::assertSame(['seconds' => 5700, 'sessions' => 4, 'activeDays' => 3, 'averageSeconds' => 1425], $week['current']);
        self::assertSame(['seconds' => 3000, 'sessions' => 2, 'activeDays' => 2, 'averageSeconds' => 1500], $week['previous']);
        self::assertSame(90, $week['change']);
        self::assertSame('A', $week['grade']);
        self::assertCount(7, $week['buckets']);
        self::assertSame([2700, 1800, 1200, 0, 0, 0, 0], array_column($week['buckets'], 'seconds'));
        self::assertSame([false, false, false, true, true, true, true], array_column($week['buckets'], 'isFuture'));
        self::assertCount(3, $week['daily']);
        self::assertSame([2, 1, 1], array_column($week['daily'], 'sessions'));
        self::assertSame(['RUNNING', 'CYCLING'], array_column($week['disciplines'], 'discipline'));
        self::assertSame([3900, 1800], array_column($week['disciplines'], 'seconds'));
        self::assertEqualsWithDelta(3900 / 5700, $week['disciplines'][0]['share'], 0.000001);

        $month = $this->stats($bob, 'month');
        self::assertSame('2026-06-16T00:00:00+02:00', $month['previousEnd']);
        self::assertSame(['seconds' => 15300, 'sessions' => 8, 'activeDays' => 7, 'averageSeconds' => 1912.5], $month['current']);
        self::assertSame(5000, $month['previous']['seconds']);
        self::assertSame(206, $month['change']);
        self::assertCount(31, $month['buckets']);
        self::assertCount(15, $month['daily']);
    }

    public function testEmptyDataStillReturnsTheCalendarAndNoComparison(): void
    {
        $bob = $this->openAccount();
        $this->at('2026-01-01T00:00:00Z');

        foreach (['week' => 7, 'month' => 31, 'year' => 12] as $period => $points) {
            $stats = $this->stats($bob, $period);
            self::assertSame(['seconds' => 0, 'sessions' => 0, 'activeDays' => 0, 'averageSeconds' => 0], $stats['current']);
            self::assertSame($stats['current'], $stats['previous']);
            self::assertNull($stats['change']);
            self::assertNull($stats['grade']);
            self::assertSame([], $stats['disciplines']);
            self::assertCount($points, $stats['buckets']);
            self::assertSame(0, array_sum(array_column($stats['buckets'], 'seconds')));
        }
    }

    public function testAuthAndInvalidPeriodUseExistingProblemResponses(): void
    {
        self::assertSame(401, $this->get('/api/workouts/stats')->getStatusCode());
        $bob = $this->openAccount();
        foreach (['day', '', 'week[]'] as $period) {
            $response = $this->get('/api/workouts/stats?'.http_build_query(['period' => $period]), $bob->headers);
            self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
            self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
            self::assertSame(422, self::decode($response)['status']);
        }
        self::assertSame(422, $this->get('/api/workouts/stats?period[]=week', $bob->headers)->getStatusCode());
    }

    public function testProfileTimezoneIsRereadAndEveryWindowHasInclusiveStartAndExclusiveEnd(): void
    {
        $bob = $this->openAccount();
        $this->at('2026-01-04T16:00:00Z');
        $this->workout($bob, '2025-12-28T14:59:59Z', 10);
        $this->workout($bob, '2025-12-28T15:00:00Z', 100);
        $this->workout($bob, '2025-12-29T14:59:59Z', 200);
        $this->workout($bob, '2025-12-29T15:00:00Z', 400);
        $this->workout($bob, '2026-01-04T14:59:59Z', 800);
        $this->workout($bob, '2026-01-04T15:00:00Z', 1600);
        $this->workout($bob, '2026-01-05T14:59:59Z', 3200);
        $this->workout($bob, '2026-01-05T15:00:00Z', 6400);
        self::assertSame('2025-12-29T00:00:00+01:00', $this->stats($bob)['start']);

        self::assertSame(200, $this->send('PATCH', '/api/me', ['timezone' => 'Asia/Tokyo'], $bob->headers)->getStatusCode());
        $stats = $this->stats($bob);
        self::assertSame('Asia/Tokyo', $stats['timezone']);
        self::assertSame('2026-01-05T00:00:00+09:00', $stats['start']);
        self::assertSame('2026-01-06T00:00:00+09:00', $stats['observedEnd']);
        self::assertSame(['seconds' => 4800, 'sessions' => 2, 'activeDays' => 1, 'averageSeconds' => 2400], $stats['current']);
        self::assertSame(['seconds' => 300, 'sessions' => 2, 'activeDays' => 1, 'averageSeconds' => 150], $stats['previous']);
    }

    #[DataProvider('dstDays')]
    public function testSqlGroupsTheDstDayInTheProfileTimezone(string $now, string $from, string $to, string $first, string $second, int $hours): void
    {
        $bob = $this->openAccount();
        $this->at($now);
        $this->workout($bob, $from, 600);
        $this->workout($bob, $first, 600);
        $this->workout($bob, $second, 600);
        $this->workout($bob, $to, 1200);

        $stats = $this->stats($bob);
        self::assertSame(['seconds' => 1800, 'sessions' => 3, 'activeDays' => 1, 'averageSeconds' => 600], $stats['current']);
        self::assertNull($stats['change']);
        self::assertNull($stats['grade']);
        $sunday = $stats['buckets'][6];
        self::assertSame($from, $sunday['from']);
        self::assertSame($to, $sunday['to']);
        self::assertSame($hours * 3600, new DateTimeImmutable($sunday['to'])->getTimestamp() - new DateTimeImmutable($sunday['from'])->getTimestamp());
        self::assertSame(3, $sunday['sessions']);
        self::assertSame(1, $sunday['activeDays']);
    }

    /** @return iterable<string, array{string, string, string, string, string, int}> */
    public static function dstDays(): iterable
    {
        yield '23 hours' => ['2026-03-29T12:00:00Z', '2026-03-29T00:00:00+01:00', '2026-03-30T00:00:00+02:00', '2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z', 23];
        yield '25 hours, repeated local hour' => ['2026-10-25T12:00:00Z', '2026-10-25T00:00:00+02:00', '2026-10-26T00:00:00+01:00', '2026-10-25T00:30:00Z', '2026-10-25T01:30:00Z', 25];
    }

    public function testYearBucketsAreMonthlyAndComparisonCountsCivilDaysInLeapYears(): void
    {
        $bob = $this->openAccount();
        $this->at('2024-03-01T12:00:00Z');
        $this->workout($bob, '2024-01-01T07:00:00Z', 120);
        $this->workout($bob, '2024-01-02T07:00:00Z', 240);
        $this->workout($bob, '2024-02-29T07:00:00Z', 360);
        $this->workout($bob, '2024-03-01T07:00:00Z', 480);
        $this->workout($bob, '2024-03-02T07:00:00Z', 1000);
        $this->workout($bob, '2023-01-01T07:00:00Z', 600);
        $this->workout($bob, '2023-03-02T07:00:00Z', 300);
        $this->workout($bob, '2023-03-03T07:00:00Z', 2000);

        $stats = $this->stats($bob, 'year');
        self::assertSame('2023-03-03T00:00:00+01:00', $stats['previousEnd']);
        self::assertSame(1200, $stats['current']['seconds']);
        self::assertSame(900, $stats['previous']['seconds']);
        self::assertSame(33, $stats['change']);
        self::assertSame(61, $stats['days']);
        self::assertCount(61, $stats['daily']);
        self::assertCount(12, $stats['buckets']);
        self::assertSame([360, 360, 480, 0, 0, 0, 0, 0, 0, 0, 0, 0], array_column($stats['buckets'], 'seconds'));
        self::assertSame([2, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0], array_column($stats['buckets'], 'activeDays'));
        self::assertSame([false, false, false, true, true, true, true, true, true, true, true, true], array_column($stats['buckets'], 'isFuture'));
    }

    public function testRepeatedImportsDoNotDuplicateVolumeAndUncreditedHistoryCounts(): void
    {
        $bob = $this->openAccount();
        $this->at('2020-07-15T12:00:00Z');
        $candidate = ['externalId' => 'old-archive', 'source' => 'APPLE_HEALTH', 'activityType' => 'running', 'startedAt' => '2020-07-13T07:00:00Z', 'endedAt' => '2020-07-13T08:00:00Z'];
        foreach (['first', 'retry'] as $key) {
            $response = $this->post('/api/workouts/import', ['workouts' => [$candidate, $candidate]], $bob->headers + ['Idempotency-Key' => $key]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            self::assertSame(3600, $this->stats($bob)['current']['seconds']);
            self::assertSame(1, $this->stats($bob)['current']['sessions']);
        }
        $credits = $this->connection()->fetchOne('SELECT COUNT(*) FROM xp_transaction');
        self::assertIsNumeric($credits);
        self::assertSame(0, (int) $credits);
    }

    public function testOneGroupedWorkoutQueryHandlesMoreThanAnHistoryPage(): void
    {
        $bob = $this->openAccount();
        $this->at('2026-07-15T12:00:00Z');
        for ($i = 0; $i < 120; ++$i) {
            $at = new DateTimeImmutable('2026-07-01T00:00:00Z')->modify('+'.($i * 15).' minutes');
            $this->workout($bob, $at->format('c'), 600, 0 === $i % 2 ? 'RUNNING' : 'CYCLING');
        }
        $this->client->enableProfiler();
        $stats = $this->stats($bob, 'month');
        self::assertSame(72000, $stats['current']['seconds']);
        self::assertSame(120, $stats['current']['sessions']);
        self::assertSame(2, $stats['current']['activeDays']);
        self::assertSame(2, $stats['buckets'][0]['activeDays'] + $stats['buckets'][1]['activeDays']);
        self::assertSame(['CYCLING', 'RUNNING'], array_column($stats['disciplines'], 'discipline'));

        $profile = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);
        $workoutQueries = [];
        foreach ($collector->getQueries() as $queries) {
            self::assertIsArray($queries);
            foreach ($queries as $query) {
                self::assertIsArray($query);
                $sql = $query['sql'] ?? '';
                self::assertIsString($sql);
                if (str_contains($sql, 'FROM workout')) {
                    $workoutQueries[] = $sql;
                }
            }
        }
        self::assertCount(1, $workoutQueries);
        self::assertStringContainsString('GROUP BY', $workoutQueries[0]);
        self::assertStringContainsString('SUM(duration_seconds)', $workoutQueries[0]);
    }

    private function at(string $instant): void
    {
        // @phpstan-ignore symfonyContainer.serviceNotFound (Horloge réservée au conteneur test.)
        $clock = self::getContainer()->get('test.training_stats.clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify($instant);
    }

    private function workout(Account $account, string $startedAt, int $seconds, string $discipline = 'RUNNING', ?string $externalId = null): void
    {
        $this->recordWorkout($account, $discipline, $seconds, new DateTimeImmutable($startedAt)->modify('+'.$seconds.' seconds'), externalId: $externalId);
    }

    /** @return Stats */
    private function stats(Account $account, ?string $period = null): array
    {
        $response = $this->get('/api/workouts/stats'.(null === $period ? '' : '?period='.$period), $account->headers);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var Stats $body */
        $body = self::decode($response);

        return $body;
    }
}
