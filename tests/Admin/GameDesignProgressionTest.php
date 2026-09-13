<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignProgram;
use App\Admin\Domain\GameDesignProgression;
use PHPUnit\Framework\TestCase;

/** @phpstan-import-type ProgramInput from GameDesignProgram */
final class GameDesignProgressionTest extends TestCase
{
    public function testChronologyUsesAllSportsForDiminishingAndOneSportForCaps(): void
    {
        $program = $this->program();
        $program['sessions'] = [
            ['day' => 1, 'hour' => 10, 'minute' => 0, 'discipline' => 'RUNNING', 'duration' => 3600, 'distance' => null, 'elevation' => null],
            ['day' => 1, 'hour' => 8, 'minute' => 0, 'discipline' => 'RUNNING', 'duration' => 3600, 'distance' => null, 'elevation' => null],
            ['day' => 1, 'hour' => 9, 'minute' => 0, 'discipline' => 'CYCLING', 'duration' => 3600, 'distance' => null, 'elevation' => null],
        ];
        $snapshot = GameDesignFixture::snapshot();
        $result = new GameDesignProgression()->compare($snapshot, $snapshot, GameDesignFixture::profile(), $program);
        self::assertSame($result['published'], $result['draft']);
        /** @var list<array{xp: int, state: array{totalXp: int, averageEnergy: int}} > $sessions */
        $sessions = $result['published']['sessions'];
        self::assertSame([60, 30, 10, 60, 30, 10], array_column($sessions, 'xp'));
        self::assertSame(10100, $sessions[2]['state']['totalXp']);
        self::assertSame(100, $sessions[0]['state']['averageEnergy']);
        /** @var list<array{state: array{averageEnergy: int, vitality: int, totalXp: int}} > $weeks */
        $weeks = $result['published']['weeks'];
        self::assertSame(700, $weeks[0]['state']['averageEnergy']);
        self::assertSame(10200, $weeks[1]['state']['totalXp']);
    }

    public function testOverlapMinimumAndDurationCapReuseImportRules(): void
    {
        $program = $this->program();
        $program['weeks'] = 1;
        $program['sessions'] = [
            ['day' => 1, 'hour' => 8, 'minute' => 0, 'discipline' => 'RUNNING', 'duration' => 3600, 'distance' => null, 'elevation' => null],
            ['day' => 1, 'hour' => 8, 'minute' => 5, 'discipline' => 'RUNNING', 'duration' => 7200, 'distance' => 1000, 'elevation' => 100],
            ['day' => 2, 'hour' => 8, 'minute' => 0, 'discipline' => 'RUNNING', 'duration' => 30, 'distance' => null, 'elevation' => null],
        ];
        $snapshot = GameDesignFixture::snapshot();
        $result = new GameDesignProgression()->compare($snapshot, $snapshot, GameDesignFixture::profile(), $program);
        /** @var list<array{xp: int, reason: ?string, retained: int}> $sessions */
        $sessions = $result['draft']['sessions'];
        self::assertSame('Chevauchement', $sessions[0]['reason']);
        self::assertSame(62, $sessions[1]['xp']);
        self::assertSame(3600, $sessions[1]['retained']);
        self::assertSame('Durée sous le minimum', $sessions[2]['reason']);
    }

    public function testRestDaysAndDstKeepLocalTimeAndIgnoreTheVitalityOverride(): void
    {
        $program = $this->program();
        $program['startDate'] = '2026-03-23';
        $program['sessions'] = [['day' => 7, 'hour' => 1, 'minute' => 30, 'discipline' => 'RUNNING', 'duration' => 7200, 'distance' => null, 'elevation' => null]];
        $profile = GameDesignFixture::profile();
        $profile['vitalityOverride'] = 999999;
        $snapshot = GameDesignFixture::snapshot();
        $result = new GameDesignProgression()->compare($snapshot, $snapshot, $profile, $program);
        /** @var list<array{at: string, end: string, duration: int, state: array{vitality: int}}> $sessions */
        $sessions = $result['draft']['sessions'];
        self::assertSame('2026-03-29T01:30:00+01:00', $sessions[0]['at']);
        self::assertSame('2026-03-29T04:30:00+02:00', $sessions[0]['end']);
        self::assertSame(7200, $sessions[0]['duration']);
        self::assertNotSame(999999, $sessions[0]['state']['vitality']);
        self::assertSame(0, $result['draft']['streakBonus']);
        self::assertSame($result, new GameDesignProgression()->compare($snapshot, $snapshot, $profile, $program));
    }

    /** @return ProgramInput */
    private function program(): array
    {
        return ['name' => 'Deux semaines', 'startDate' => '2026-09-14', 'timezone' => 'Europe/Paris', 'weeks' => 2, 'sessions' => [], 'energy' => [700, 700, 700, 700, 700, 700, 700]];
    }
}
