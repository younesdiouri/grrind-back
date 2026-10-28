<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\GuildWeek;
use Symfony\Component\Uid\Uuid;

/** Rang de compétition ; l'ancienneté puis l'UUID stabilisent seulement l'affichage des ex æquo. */
final readonly class GuildWeeklyLeaderboard
{
    /** @var list<array{member: GuildMemberView, workoutCount: int, rank: ?int}> */
    public array $entries;
    public int $totalWorkouts;
    public int $activeMemberCount;

    /**
     * @param list<GuildMemberView> $members
     * @param array<string, int>    $counts
     */
    public function __construct(public Uuid $guildId, public GuildWeek $week, array $members, array $counts)
    {
        usort($members, static fn (GuildMemberView $a, GuildMemberView $b): int => [-($counts[$a->playerId->toRfc4122()] ?? 0), $a->joinedAt, $a->playerId->toRfc4122()]
            <=> [-($counts[$b->playerId->toRfc4122()] ?? 0), $b->joinedAt, $b->playerId->toRfc4122()]);
        $entries = [];
        $total = $active = 0;
        $previous = $rank = null;
        foreach ($members as $position => $member) {
            $count = $counts[$member->playerId->toRfc4122()] ?? 0;
            if ($count !== $previous) {
                $rank = 0 === $count ? null : $position + 1;
            }
            $entries[] = ['member' => $member, 'workoutCount' => $count, 'rank' => $rank];
            $total += $count;
            $active += $count > 0 ? 1 : 0;
            $previous = $count;
        }
        $this->entries = $entries;
        $this->totalWorkouts = $total;
        $this->activeMemberCount = $active;
    }
}
