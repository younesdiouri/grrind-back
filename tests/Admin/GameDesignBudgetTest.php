<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\Domain\GameDesignCombat;
use App\Admin\Domain\GameDesignProgression;
use PHPUnit\Framework\TestCase;

/** Mesure reproductible du budget technique, sans seuil de durée fragile selon la machine. */
final class GameDesignBudgetTest extends TestCase
{
    public function testMaximumCombatAndProgramBudgets(): void
    {
        $snapshot = GameDesignFixture::snapshot();
        /** @var array{combat: array{fighter: array<string,int>}} $snapshot */
        $snapshot['combat']['fighter']['max_attacks'] = 10000;
        $snapshot['combat']['fighter']['base_hp'] = 1000000;
        $enemy = GameDesignFixture::enemy();
        $enemy['hp'] = 100000000;
        $enemy['damage'] = 1;
        $started = hrtime(true);
        $combats = new GameDesignCombat()->compare($snapshot, $snapshot, GameDesignFixture::profile(), '', $enemy, 25, 42);
        $combatMs = (hrtime(true) - $started) / 1000000;
        self::assertSame(25, $combats['published']['limits']);
        self::assertSame(25, $combats['draft']['limits']);
        $sessions = [];
        for ($day = 1; $day <= 7; ++$day) {
            foreach ([8, 18] as $hour) {
                $sessions[] = ['day' => $day, 'hour' => $hour, 'minute' => 0, 'discipline' => 'RUNNING', 'duration' => 3600, 'distance' => 10000, 'elevation' => 100];
            }
        }
        $program = ['name' => 'Maximum', 'startDate' => '2026-01-05', 'timezone' => 'Europe/Paris', 'weeks' => 52, 'sessions' => $sessions, 'energy' => [500, 500, 500, 500, 500, 500, 500]];
        $started = hrtime(true);
        $result = new GameDesignProgression()->compare($snapshot, $snapshot, GameDesignFixture::profile(), $program);
        $progressionMs = (hrtime(true) - $started) / 1000000;
        self::assertIsArray($result['published']['sessions']);
        self::assertCount(728, $result['published']['sessions']);
        self::assertIsArray($result['draft']['weeks']);
        self::assertCount(52, $result['draft']['weeks']);
        fwrite(\STDERR, \sprintf("\n#277 budget : 500000 tentatives = %.1f ms ; 2 × 728 séances = %.1f ms ; pic mémoire = %.1f MiB\n", $combatMs, $progressionMs, memory_get_peak_usage(true) / 1048576));
    }
}
