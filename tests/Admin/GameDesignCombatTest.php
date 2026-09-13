<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignCombat;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class GameDesignCombatTest extends TestCase
{
    public function testIdenticalSnapshotsAndSeedsGiveIdenticalResults(): void
    {
        $engine = new GameDesignCombat();
        $snapshot = GameDesignFixture::snapshot();
        $result = $engine->compare($snapshot, $snapshot, GameDesignFixture::profile(), '', GameDesignFixture::enemy(), 20, 123);
        self::assertSame($result['published'], $result['draft']);
        self::assertSame($result, $engine->compare($snapshot, $snapshot, GameDesignFixture::profile(), '', GameDesignFixture::enemy(), 20, 123));
        self::assertIsInt($result['draft']['wins']);
        self::assertIsInt($result['draft']['defeats']);
        self::assertSame(20, $result['draft']['wins'] + $result['draft']['defeats']);
        self::assertNotEmpty($result['draft']['detail']);
    }

    public function testMissingAdversariesAreNeverSilentlyReplaced(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('indisponible');
        new GameDesignCombat()->compare(GameDesignFixture::snapshot(), GameDesignFixture::snapshot(), GameDesignFixture::profile(), 'MISSING', null, 1, 1);
    }

    public function testTheCombinedAttackBudgetBoundsBothSeries(): void
    {
        $snapshot = GameDesignFixture::snapshot();
        /** @var array{combat: array{fighter: array<string, int>}} $snapshot */
        $snapshot['combat']['fighter']['max_attacks'] = 10000;
        self::assertSame(25, GameDesignCombat::maximumSamples($snapshot, $snapshot));
        $this->expectException(InvalidArgumentException::class);
        new GameDesignCombat()->compare($snapshot, $snapshot, GameDesignFixture::profile(), '', GameDesignFixture::enemy(), 26, 1);
    }
}
