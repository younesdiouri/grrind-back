<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignProgression;
use App\Progression\Application\VitalityBonusProvider;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Domain\Activity\Vitality;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeZone;

final class GameDesignParityTest extends ApiTestCase
{
    use Workouts;

    public function testFictionalWeekMatchesARealMixedImportAndDailyEnergyWindow(): void
    {
        $account = $this->openAccount();
        $start = self::daysAgo(7)->setTimezone(new DateTimeZone('Europe/Paris'))->setTime(0, 0);
        $day = (int) $start->format('N');
        $sessions = [
            ['day' => $day, 'hour' => 11, 'minute' => 0, 'discipline' => 'RUNNING', 'duration' => 3600, 'distance' => 5000, 'elevation' => 50],
            ['day' => $day, 'hour' => 8, 'minute' => 0, 'discipline' => 'RUNNING', 'duration' => 7200, 'distance' => 10000, 'elevation' => 100],
            ['day' => $day, 'hour' => 10, 'minute' => 0, 'discipline' => 'CYCLING', 'duration' => 3600, 'distance' => 20000, 'elevation' => 200],
            ['day' => $day, 'hour' => 8, 'minute' => 5, 'discipline' => 'RUNNING', 'duration' => 3600, 'distance' => null, 'elevation' => null],
        ];
        $workouts = [];
        foreach ($sessions as $index => $session) {
            $at = $start->setTime($session['hour'], $session['minute']);
            $workouts[] = ['externalId' => 'parity-'.$index, 'source' => 'APPLE_HEALTH', 'activityType' => strtolower($session['discipline']), 'startedAt' => $at->format(\DATE_ATOM), 'endedAt' => $at->modify('+'.$session['duration'].' seconds')->format(\DATE_ATOM), 'distanceMeters' => $session['distance'], 'elevationGainMeters' => $session['elevation']];
        }
        $response = $this->post('/api/workouts/import', ['workouts' => $workouts], $account->headers + ['Idempotency-Key' => 'parity']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $days[] = ['day' => $start->modify('+'.$i.' days')->format('Y-m-d'), 'activeEnergyKcal' => 500, 'source' => 'APPLE_HEALTH'];
        }
        $response = $this->send('PUT', '/api/daily-activity', ['days' => $days], $account->headers);
        self::assertSame(204, $response->getStatusCode());
        $rulesets = self::getContainer()->get(GameRulesets::class);
        $snapshot = $rulesets->snapshot();
        $profile = ['name' => 'Nouveau', 'strength' => 0, 'endurance' => 0, 'mobility' => 0, 'dexterity' => 0, 'vitalityOverride' => null, 'equipment' => []];
        $program = ['name' => 'Parité', 'startDate' => $start->format('Y-m-d'), 'timezone' => 'Europe/Paris', 'weeks' => 1, 'sessions' => $sessions, 'energy' => [500, 500, 500, 500, 500, 500, 500]];
        $simulated = new GameDesignProgression()->compare($snapshot, $snapshot, $profile, $program);
        /** @var list<array{state: array{totalXp: int, attributes: array<string,int>, vitality: int, averageEnergy: int}}> $weeks */
        $weeks = $simulated['published']['weeks'];
        $state = $weeks[0]['state'];
        $ledger = self::getContainer()->get(XpTransactionRepository::class);
        $attributes = $ledger->attributeTotalsOf($account->id);
        self::assertSame($ledger->totalOf($account->id), $state['totalXp']);
        self::assertSame($attributes->toArray(), $state['attributes']);
        $vitality = Vitality::runtime($rulesets);
        $bonus = self::getContainer()->get(VitalityBonusProvider::class)->of([$account->id->toRfc4122() => $vitality->of($attributes)], [$account->id], $start->modify('+6 days'));
        self::assertSame($bonus[$account->id->toRfc4122()]->value, $state['vitality']);
        self::assertSame($bonus[$account->id->toRfc4122()]->breakdown->windowAverageActiveKcal, $state['averageEnergy']);
    }
}
