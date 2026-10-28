<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Application\GuildWeeklyLeaderboard;
use App\Community\Domain\GuildWeek;

final readonly class GuildWeeklyLeaderboardResource
{
    public function __construct(private GuildWeeklyLeaderboard $leaderboard)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $board = $this->leaderboard;

        return [
            'guildId' => $board->guildId->toRfc4122(),
            'weekStartsAt' => $board->week->startsAt->format('c'),
            'weekEndsAt' => $board->week->endsAt->format('c'),
            'timezone' => GuildWeek::TIMEZONE,
            'totalWorkouts' => $board->totalWorkouts,
            'activeMemberCount' => $board->activeMemberCount,
            'entries' => array_map(static fn (array $entry): array => [
                'member' => GuildMemberResource::from($entry['member'])->toArray(),
                'workoutCount' => $entry['workoutCount'],
                'rank' => $entry['rank'],
            ], $board->entries),
        ];
    }
}
