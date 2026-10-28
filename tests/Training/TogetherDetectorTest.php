<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Training\Domain\Together\TogetherDetector;
use App\Training\Domain\Together\TogetherRules;
use App\Training\Domain\Together\TogetherTrack;
use PHPUnit\Framework\TestCase;

final class TogetherDetectorTest extends TestCase
{
    public function testOneToFivePlayersAndTheCap(): void
    {
        $detector = new TogetherDetector(new TogetherRules(TogetherRules::PILOT));
        for ($size = 1; $size <= 5; ++$size) {
            $tracks = [];
            for ($i = 0; $i < $size; ++$i) {
                $tracks[] = self::track((string) $i);
            }
            self::assertCount(min(4, $size), $detector->groupFor($tracks[0], $tracks));
        }
    }

    public function testItRequiresTheSameSportAndEightyPercentOfBothSessions(): void
    {
        $a = self::track('a');
        $detector = new TogetherDetector(new TogetherRules(TogetherRules::PILOT));
        self::assertCount(2, $detector->groupFor($a, [$a, self::track('b', start: 360)]));
        self::assertCount(1, $detector->groupFor($a, [$a, self::track('b', start: 361)]));
        self::assertCount(1, $detector->groupFor($a, [$a, self::track('b', sport: 'cycling')]));
    }

    public function testThereIsNoTransitiveGroupOrDuplicatePlayer(): void
    {
        $a = self::track('a', longitude: 2.0);
        $b = self::track('b', longitude: 2.001);
        $c = self::track('c', longitude: 2.002);
        $detector = new TogetherDetector(new TogetherRules(TogetherRules::PILOT));
        self::assertCount(2, $detector->groupFor($a, [$a, $b, $c]));
        self::assertCount(1, $detector->groupFor($a, [$a, self::track('other', user: 'a')]));
    }

    public function testSparseInaccurateAndDistantTracesAreNotProof(): void
    {
        $a = self::track('a');
        $detector = new TogetherDetector(new TogetherRules(TogetherRules::PILOT));
        foreach ([self::track('b', accuracy: 500), self::track('b', longitude: 3.0), new TogetherTrack('b', 'b', 'running', 0, 1800, [])] as $b) {
            self::assertCount(1, $detector->groupFor($a, [$a, $b]));
        }
        $onePoint = self::track('b')->points[0];
        self::assertCount(1, $detector->groupFor($a, [$a, new TogetherTrack('b', 'b', 'running', 0, 1800, [$onePoint])]));
    }

    public function testEveryPairMustBeCloseAtTheSameTimes(): void
    {
        $a = self::track('a');
        $b = self::track('b', start: 360);
        $c = self::track('c', start: -360);
        $detector = new TogetherDetector(new TogetherRules(TogetherRules::PILOT));
        self::assertCount(2, $detector->groupFor($a, [$a, $b, $c]));
    }

    public function testDistanceThresholdIsInclusive(): void
    {
        $rules = TogetherRules::PILOT;
        $rules['distance_meters'] = 100;
        $detector = new TogetherDetector(new TogetherRules($rules));
        $a = self::track('a', longitude: 0);
        self::assertCount(2, $detector->groupFor($a, [$a, self::track('b', longitude: 0.0008)]));
        self::assertCount(1, $detector->groupFor($a, [$a, self::track('b', longitude: 0.002)]));
    }

    private static function track(string $id, int $start = 0, string $sport = 'running', float $longitude = 2.0, float $accuracy = 5.0, ?string $user = null): TogetherTrack
    {
        $points = [];
        for ($at = $start; $at <= $start + 1800; $at += 15) {
            $points[] = ['at' => $at, 'latitude' => 48.0, 'longitude' => $longitude, 'accuracy' => $accuracy];
        }

        return new TogetherTrack($id, $user ?? $id, $sport, $start, $start + 1800, $points);
    }
}
