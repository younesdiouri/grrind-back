<?php

declare(strict_types=1);

namespace App\Tests\Engagement;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * Le streak de l'assiduité (#346) contre la vraie route : une semaine pointée 7/7 rapporte
 * un coffre au pointage suivant, une seule fois, au palier de ses jours de sport.
 */
final class StreakCheckInTest extends ApiTestCase
{
    use Workouts;

    public function testAFullWeekGrantsItsChestOnceAtTheTierOfItsSportDays(): void
    {
        $bob = $this->openAccount();
        [$today, $lastMonday] = $this->days();
        $this->checkInFrom($bob, $lastMonday, $today);
        // Quatre jours de sport, dont un à deux séances : des jours, pas des séances → Rare.
        $workouts = [];
        foreach ([0, 1, 2, 3, 3] as $index => $offset) {
            $startedAt = $lastMonday->modify(\sprintf('+%d days', $offset))->setTime(8 + $index, 0);
            $workouts[] = ['externalId' => 'HK-'.$index, 'source' => 'APPLE_HEALTH', 'activityType' => 'running', 'startedAt' => $startedAt->format(DateTimeInterface::ATOM), 'endedAt' => $startedAt->modify('+40 minutes')->format(DateTimeInterface::ATOM)];
        }
        $this->post('/api/workouts/import', ['workouts' => $workouts], $bob->headers + ['Idempotency-Key' => 'semaine']);

        $first = self::decode($this->post('/api/streak/check-in', [], $bob->headers));
        $second = self::decode($this->post('/api/streak/check-in', [], $bob->headers));

        self::assertIsArray($first['chest']);
        self::assertSame('IRON_BOUND_CHEST', $first['chest']['key']);
        self::assertNull($second['chest']);
        self::assertSame(1, $this->chestsOf($bob, 'IRON_BOUND_CHEST'));
        self::assertSame(['days' => (int) $lastMonday->diff($today)->days + 1, 'checkedInToday' => true, 'sportDaysThisWeek' => 0, 'chestRarity' => 'COMMON'], $second['streak']);
    }

    public function testAWeekWithAMissedDayGrantsNothing(): void
    {
        $bob = $this->openAccount();
        [$today, $lastMonday] = $this->days();
        $this->checkInFrom($bob, $lastMonday->modify('+1 day'), $today);

        $body = self::decode($this->post('/api/streak/check-in', [], $bob->headers));

        self::assertNull($body['chest']);
        self::assertSame(0, $this->chestsOf($bob, 'WOODEN_CHEST'));
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} aujourd'hui et le lundi de la semaine passée, à Paris */
    private function days(): array
    {
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $today = $clock->now()->setTimezone(new DateTimeZone('Europe/Paris'))->setTime(0, 0);

        return [$today, $today->modify('monday this week')->modify('-7 days')];
    }

    /** Pointe chaque jour de `$from` jusqu'à la veille de `$today`. */
    private function checkInFrom(Account $account, DateTimeImmutable $from, DateTimeImmutable $today): void
    {
        for ($day = $from; $day < $today; $day = $day->modify('+1 day')) {
            $this->connection()->insert('engagement_check_in', ['user_id' => $account->id->toRfc4122(), 'day' => $day->format('Y-m-d')]);
        }
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
}
