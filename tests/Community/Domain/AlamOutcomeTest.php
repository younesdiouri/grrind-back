<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\AlamOutcome;
use App\Shared\Domain\Alam\AlamCalendar;
use App\Shared\Domain\Alam\AlamRules;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class AlamOutcomeTest extends TestCase
{
    public static function rules(): AlamRules
    {
        return new AlamRules(['strength_target' => 100, 'endurance_target' => 100, 'mobility_target' => 100, 'dexterity_target' => 100, 'vitality_target' => 400, 'presentation_seconds' => 60, 'memory_limit' => 20, 'resource_base' => 1, 'resource_progression' => 4, 'activity_cap_permille' => 3000, 'deficit_exponent' => 8, 'rng_cap_permille' => 250, 'rng_base_permille' => 200, 'surplus_weight_permille' => 100, 'equipment_chance_permille' => 100, 'legendary_chance_permille' => 10, 'start_hour' => 19, 'reset_hour' => 20, 'timezone' => 'Europe/Paris', 'resource_key' => 'NAFS_ESSENCE', 'enemy_key' => 'AL_KASAL', 'encounter_thresholds' => [250, 550, 1000]]);
    }

    public function testGuaranteeZeroAndDeficitMonotonicity(): void
    {
        $rules = self::rules();
        $engine = new AlamOutcome($rules);
        $targets = $rules->targets(1);
        self::assertSame(1000000, $engine->probabilityMillionths($targets, $targets));
        $gains = $targets;
        $gains['strength'] = 10000;
        $previous = 0;
        for ($mobility = 0; $mobility < 100; ++$mobility) {
            $gains['mobility'] = $mobility;
            $probability = $engine->probabilityMillionths($gains, $targets);
            self::assertGreaterThanOrEqual($previous, $probability);
            self::assertLessThanOrEqual(250000, $probability);
            $previous = $probability;
        }
        $gains['mobility'] = 0;
        self::assertSame(0, $engine->probabilityMillionths($gains, $targets));
        $gains['mobility'] = 40;
        $low = $engine->probabilityMillionths($gains, $targets);
        $gains['mobility'] = 96;
        self::assertGreaterThan($low * 100, $engine->probabilityMillionths($gains, $targets));
    }

    public function testCivilCalendarAndExcludedOverlapAcrossDst(): void
    {
        $calendar = new AlamCalendar(self::rules());
        foreach (['2026-03-23 12:00:00', '2026-10-19 12:00:00'] as $date) {
            $week = $calendar->week(new DateTimeImmutable($date.' Europe/Paris'));
            self::assertSame('19:00', $week['close']->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i'));
            self::assertSame('20:00', $week['reset']->setTimezone(new DateTimeZone('Europe/Paris'))->format('H:i'));
            self::assertSame(3600, $calendar->retainedSeconds($week['close']->modify('-30 minutes'), $week['reset']->modify('+30 minutes')));
            self::assertSame(0, $calendar->retainedSeconds($week['close'], $week['reset']));
            self::assertEquals($week['reset'], $calendar->week($week['reset'])['start']);
        }
    }
}
