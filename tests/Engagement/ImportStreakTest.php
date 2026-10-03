<?php

declare(strict_types=1);

namespace App\Tests\Engagement;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le streak (#286) contre la vraie transaction d'import : le coffre tombe à la sixième
 * journée sportive d'une semaine, une seule fois, quel que soit le découpage des imports.
 */
final class ImportStreakTest extends ApiTestCase
{
    use Workouts;

    public function testTheSixthSportDayOfAWeekGrantsACommonChestOnce(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, array_map(self::session(...), [7, 6, 5, 4, 3, 2])));

        self::assertIsArray($body['imported']);
        self::assertCount(6, $body['imported']);
        $last = $body['imported'][5];
        self::assertIsArray($last);
        $streak = $last['streak'];
        self::assertIsArray($streak);
        self::assertTrue($streak['dayCounted']);
        self::assertIsArray($streak['before']);
        self::assertIsArray($streak['after']);
        self::assertSame(0, $streak['before']['weeksCompleted']);
        self::assertSame(1, $streak['after']['weeksCompleted']);
        self::assertIsArray($streak['chests']);
        self::assertCount(1, $streak['chests']);
        self::assertIsArray($streak['chests'][0]);
        self::assertSame('WOODEN_CHEST', $streak['chests'][0]['key']);
        self::assertSame(1, $this->chestsOf($bob, 'WOODEN_CHEST'));

        // Un rejeu sous une autre clé : les séances sont des doublons, rien ne retombe.
        $this->import($bob, array_map(self::session(...), [7, 6, 5, 4, 3, 2]), 'rejeu');
        self::assertSame(1, $this->chestsOf($bob, 'WOODEN_CHEST'));
        self::assertEquals(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM engagement_streak_chest'));

        $state = self::decode($this->get('/api/streak', $bob->headers));
        self::assertSame(['startedOn' => self::daysAgo(7)->format('Y-m-d'), 'days' => 8, 'weeksCompleted' => 1, 'sportDaysInLast7' => 5, 'nextChestRarity' => 'RARE'], $state);
    }

    /** Une séance remontée en retard comble le trou : le coffre tombe sur elle, pas avant. */
    public function testALateSessionCompletesTheWeek(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, array_map(self::session(...), [9, 8, 6, 5]));
        self::assertSame(0, $this->chestsOf($bob, 'WOODEN_CHEST'));

        $body = self::decode($this->import($bob, [self::session(7), self::session(4)], 'plus-tard'));

        self::assertIsArray($body['imported']);
        self::assertIsArray($body['imported'][1]);
        self::assertIsArray($body['imported'][1]['streak']);
        self::assertIsArray($body['imported'][1]['streak']['chests']);
        self::assertCount(1, $body['imported'][1]['streak']['chests']);
        self::assertSame(1, $this->chestsOf($bob, 'WOODEN_CHEST'));
    }

    /** Deux séances de 20 minutes font un jour sportif ; une seule n'en fait pas un. */
    public function testShortSessionsAddUpWithinADay(): void
    {
        $bob = $this->openAccount();

        $body = self::decode($this->import($bob, [
            self::session(3, 1200, '07:00:00'),
            self::session(3, 1200, '18:00:00'),
        ]));

        self::assertIsArray($body['imported']);
        self::assertIsArray($body['imported'][0]);
        self::assertIsArray($body['imported'][1]);
        self::assertIsArray($body['imported'][0]['streak']);
        self::assertIsArray($body['imported'][1]['streak']);
        self::assertFalse($body['imported'][0]['streak']['dayCounted']);
        self::assertTrue($body['imported'][1]['streak']['dayCounted']);
    }

    /** La marche ne compte pas : six jours de marche ne font aucune série. */
    public function testWalkingDoesNotCount(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, array_map(static fn (int $day): array => self::session($day, activityType: 'walking'), [7, 6, 5, 4, 3, 2]));

        self::assertSame(0, $this->chestsOf($bob, 'WOODEN_CHEST'));
        $state = self::decode($this->get('/api/streak', $bob->headers));
        self::assertNull($state['startedOn']);
    }

    /** @param list<array<string, string>> $workouts */
    private function import(Account $account, array $workouts, string $key = 'import-du-jour'): Response
    {
        $response = $this->post('/api/workouts/import', ['workouts' => $workouts], $account->headers + ['Idempotency-Key' => $key]);
        self::assertLessThan(300, $response->getStatusCode(), (string) $response->getContent());

        return $response;
    }

    private function chestsOf(Account $account, string $key): int
    {
        $quantity = $this->connection()->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0) FROM rewards_inventory_item WHERE user_id = :id AND item_key = :key',
            ['id' => $account->id->toRfc4122(), 'key' => $key],
        );
        self::assertIsNumeric($quantity);

        return (int) $quantity;
    }

    /** @return array<string, string> */
    private static function session(int $daysAgo, int $seconds = 2400, string $time = '07:00:00', string $activityType = 'running'): array
    {
        $startedAt = self::daysAgo($daysAgo, $time);

        return [
            'externalId' => \sprintf('HK-%d-%s-%s', $daysAgo, $time, $activityType),
            'source' => 'APPLE_HEALTH',
            'activityType' => $activityType,
            'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
            'endedAt' => $startedAt->modify(\sprintf('+%d seconds', $seconds))->format(DateTimeInterface::ATOM),
        ];
    }
}
