<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Exception\GuildNotFound;
use App\Community\Domain\GuildWeek;
use App\Community\Infrastructure\Doctrine\GuildMembershipRepository;
use App\Shared\Application\WorkoutCounts;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class GuildWeeklyLeaderboardProvider
{
    public function __construct(
        private GuildMembershipRepository $memberships,
        private GuildMembersProvider $members,
        private WorkoutCounts $workouts,
        // https://symfony.com/doc/current/components/clock.html : l'instant est injectable.
        private ClockInterface $clock,
    ) {
    }

    public function of(Uuid $playerId): GuildWeeklyLeaderboard
    {
        $guild = $this->memberships->ofPlayer($playerId)?->guild() ?? throw new GuildNotFound();
        $week = GuildWeek::containing($this->clock->now());
        $members = $this->members->of($guild);
        $since = [];
        foreach ($members as $member) {
            $since[$member->playerId->toRfc4122()] = max($week->startsAt, $member->joinedAt);
        }

        return new GuildWeeklyLeaderboard($guild->id(), $week, $members, $this->workouts->since($since, $week->endsAt));
    }
}
