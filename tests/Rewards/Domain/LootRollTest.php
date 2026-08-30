<?php

declare(strict_types=1);

namespace App\Tests\Rewards\Domain;

use App\Rewards\Domain\LootRoll;
use App\Rewards\Domain\LootRollOrigin;
use App\Rewards\Domain\LootRollOutcome;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Sans base ni conteneur : ce que {@see LootRoll::record()} produit ne dépend que de ses
 * entrées — même geste que `BattleTest` pour `Battle::conclude()`. Voir
 * `LootRollPersistenceTest` pour la preuve contre une vraie base.
 */
final class LootRollTest extends TestCase
{
    public function testRecordSplitsTheOutcomeBetweenRollAndResult(): void
    {
        $roll = LootRoll::record(
            $userId = Uuid::v7(),
            LootRollOrigin::Workout,
            $causeId = Uuid::v7(),
            str_repeat("\x01", 32),
            new LootRollOutcome(
                tableKey: 'STARTER_SESSION_DROP',
                tableVersion: 1,
                effectiveLootLuckPercent: 20,
                itemRoll: 7,
                itemTotalWeight: 100,
                items: ['WORN_RUNNING_SHOES'],
                coins: 12,
            ),
            $rolledAt = new DateTimeImmutable('2026-08-30T12:00:00+00:00'),
        );

        self::assertSame($userId, $roll->userId());
        self::assertSame(LootRollOrigin::Workout, $roll->origin());
        self::assertSame($causeId, $roll->causeId());
        self::assertSame(str_repeat('01', 32), $roll->seed());
        self::assertSame('STARTER_SESSION_DROP', $roll->tableKey());
        self::assertSame(1, $roll->tableVersion());
        self::assertSame(20, $roll->effectiveLootLuckPercent());
        self::assertSame(['itemRoll' => 7, 'itemTotalWeight' => 100], $roll->roll());
        self::assertSame(['items' => ['WORN_RUNNING_SHOES'], 'coins' => 12], $roll->result());
        self::assertSame($rolledAt, $roll->rolledAt());
    }

    public function testARollWithNoItemRecordsAnEmptyItemsList(): void
    {
        $roll = LootRoll::record(
            Uuid::v7(),
            LootRollOrigin::Battle,
            Uuid::v7(),
            str_repeat("\x02", 32),
            new LootRollOutcome(
                tableKey: 'SAND_JACKAL',
                tableVersion: 1,
                effectiveLootLuckPercent: 0,
                itemRoll: 95,
                itemTotalWeight: 100,
                items: [],
                coins: 4,
            ),
            new DateTimeImmutable(),
        );

        self::assertSame(['items' => [], 'coins' => 4], $roll->result());
    }

    public function testRefusesASeedOfTheWrongLength(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LootRoll::record(
            Uuid::v7(),
            LootRollOrigin::Workout,
            Uuid::v7(),
            'trop-courte',
            new LootRollOutcome(
                tableKey: 'STARTER_SESSION_DROP',
                tableVersion: 1,
                effectiveLootLuckPercent: 0,
                itemRoll: 0,
                itemTotalWeight: 1,
                items: [],
                coins: 0,
            ),
            new DateTimeImmutable(),
        );
    }
}
