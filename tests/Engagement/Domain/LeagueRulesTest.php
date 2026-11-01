<?php

declare(strict_types=1);

namespace App\Tests\Engagement\Domain;

use App\Engagement\Domain\LeagueMovement;
use App\Engagement\Domain\LeagueRules;
use App\Engagement\Domain\LeagueTier;
use App\Engagement\Domain\LeagueWeek;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LeagueRulesTest extends TestCase
{
    public function testASoloPlayerCanClimbAndAnInactivePlayerDropsOneTier(): void
    {
        $rules = new LeagueRules(20, 5, 5);
        $active = $rules->evaluate(['solo' => ['tier' => LeagueTier::Bronze, 'score' => 10]])['solo'];
        self::assertSame(LeagueTier::Silver, $active['nextTier']);
        self::assertSame(LeagueMovement::Promoted, $active['movement']);
        self::assertSame(1, $active['rank']);
        $idle = $rules->evaluate(['solo' => ['tier' => LeagueTier::Gold, 'score' => 0]])['solo'];
        self::assertSame(LeagueTier::Silver, $idle['nextTier']);
        self::assertNull($idle['rank']);
    }

    public function testTiesShareThePromotionAndCannotCrossTheRelegationBoundary(): void
    {
        $players = [];
        foreach ([100, 100, 60, 20, 20] as $id => $score) {
            $players['p'.$id] = ['tier' => LeagueTier::Gold, 'score' => $score];
        }
        $results = new LeagueRules(20, 5, 5)->evaluate($players);
        self::assertSame([1, 1, 3, 4, 4], array_column($results, 'rank'));
        self::assertSame([LeagueTier::Platinum, LeagueTier::Platinum, LeagueTier::Gold, LeagueTier::Gold, LeagueTier::Gold], array_column($results, 'nextTier'));
        $players['p4']['score'] = 10;
        self::assertSame(LeagueTier::Silver, new LeagueRules(20, 5, 5)->evaluate($players)['p4']['nextTier']);
    }

    public function testSmallDivisionsKeepPositiveScoresAndLeagueBoundsHold(): void
    {
        $rules = new LeagueRules(20, 5, 5);
        $results = $rules->evaluate([
            'first' => ['tier' => LeagueTier::Silver, 'score' => 10],
            'last' => ['tier' => LeagueTier::Silver, 'score' => 1],
            'idle' => ['tier' => LeagueTier::Bronze, 'score' => 0],
            'champion' => ['tier' => LeagueTier::Diamond, 'score' => 100],
        ]);
        self::assertSame(LeagueTier::Silver, $results['last']['nextTier']);
        self::assertSame(LeagueTier::Bronze, $results['idle']['nextTier']);
        self::assertSame(LeagueMovement::Maintained, $results['champion']['movement']);
        self::assertSame(20, $rules->lootLuck(LeagueTier::Diamond));
        self::assertSame([], $rules->evaluate([]));
    }

    public function testWeeksFollowParisCivilTimeIncludingDaylightSaving(): void
    {
        $spring = LeagueWeek::containing(new DateTimeImmutable('2026-03-29T12:00:00Z'));
        self::assertSame('2026-03-23T00:00:00+01:00', $spring->startsAt->format('c'));
        self::assertSame(167 * 3600, $spring->endsAt->getTimestamp() - $spring->startsAt->getTimestamp());
        $autumn = LeagueWeek::containing(new DateTimeImmutable('2026-10-25T12:00:00Z'));
        self::assertSame(169 * 3600, $autumn->endsAt->getTimestamp() - $autumn->startsAt->getTimestamp());
        self::assertEquals($spring->endsAt, LeagueWeek::containing($spring->endsAt)->startsAt);
    }

    public function testInvalidPublishedSettingsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LeagueRules(50, 5, 5);
    }
}
