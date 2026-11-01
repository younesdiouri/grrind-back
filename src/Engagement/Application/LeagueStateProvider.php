<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Engagement\Domain\LeagueMovement;
use App\Engagement\Domain\LeagueRules;
use App\Engagement\Domain\LeagueTier;
use App\Engagement\Domain\LeagueWeek;
use App\Engagement\Infrastructure\Doctrine\LeagueRepository;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\LeagueScores;
use App\Shared\Application\PlayerLeagues;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Bronze est l'identité de départ ; seuls les joueurs ayant gagné de l'XP entrent dans les effectifs. */
final readonly class LeagueStateProvider implements PlayerLeagues
{
    public function __construct(private LeagueRepository $leagues, private LeagueScores $scores, private GameRulesets $rulesets, private ClockInterface $clock)
    {
    }

    public function of(array $players): array
    {
        if ([] === $players) {
            return [];
        }
        $week = LeagueWeek::containing($this->clock->now());
        $tiers = $this->leagues->tiersBefore($week->startsAt, $players);
        $rules = LeagueRules::fromSnapshot($this->rulesets->snapshot());
        $result = [];
        foreach ($players as $player) {
            $id = $player->toRfc4122();
            $result[$id] = self::identity($tiers[$id] ?? LeagueTier::Bronze, $rules);
        }

        return $result;
    }

    /** @return array<string, array{tier: LeagueTier, score: int}> */
    public function participants(LeagueWeek $week): array
    {
        $tiers = $this->leagues->tiersBefore($week->startsAt);
        $scores = $this->scores->between($week->startsAt, $week->endsAt);
        $players = [];
        foreach ($tiers as $id => $tier) {
            $players[$id] = ['tier' => $tier, 'score' => $scores[$id] ?? 0];
        }
        foreach ($scores as $id => $score) {
            if ($score > 0 && !isset($players[$id])) {
                $players[$id] = ['tier' => LeagueTier::Bronze, 'score' => $score];
            }
        }

        return $players;
    }

    /** @return array<string, mixed> */
    public function current(Uuid $player): array
    {
        $week = LeagueWeek::containing($this->clock->now());
        $rules = LeagueRules::fromSnapshot($this->rulesets->snapshot());
        $participants = $this->participants($week);
        $id = $player->toRfc4122();
        $standing = $rules->evaluate($participants)[$id] ?? null;
        $tier = $standing['tier'] ?? LeagueTier::Bronze;
        $previous = $this->leagues->lastResult($player, $week->startsAt);

        return [
            'league' => self::identity($tier, $rules),
            'weekStartsAt' => $week->startsAt->format('c'),
            'weekEndsAt' => $week->endsAt->format('c'),
            'timezone' => LeagueWeek::TIMEZONE,
            'isParticipating' => null !== $standing,
            'score' => $standing['score'] ?? 0,
            'rank' => $standing['rank'] ?? null,
            'participantCount' => $standing['participantCount'] ?? \count(array_filter($participants, static fn (array $p): bool => $p['tier'] === $tier)),
            'projectedMovement' => ($standing['movement'] ?? LeagueMovement::Maintained)->value,
            'movementPercent' => $rules->movementPercent,
            'minimumPlayersForRelegation' => $rules->minimumPlayersForRelegation,
            'lastResult' => null === $previous ? null : [
                'weekStartsAt' => new DateTimeImmutable($previous['starts_at'])->format('c'),
                'weekEndsAt' => new DateTimeImmutable($previous['ends_at'])->format('c'),
                'settledAt' => new DateTimeImmutable($previous['settled_at'])->format('c'),
                'tier' => $previous['tier'],
                'nextTier' => $previous['next_tier'],
                'movement' => LeagueMovement::between(LeagueTier::from($previous['tier']), LeagueTier::from($previous['next_tier']))->value,
                'score' => $previous['score'],
                'rank' => $previous['rank'],
                'participantCount' => $previous['participant_count'],
                'rulesetVersion' => $previous['ruleset_version'],
            ],
        ];
    }

    /** @return array{tier: string, tierOrder: int, lootLuckPercent: int} */
    private static function identity(LeagueTier $tier, LeagueRules $rules): array
    {
        return ['tier' => $tier->value, 'tierOrder' => $tier->order(), 'lootLuckPercent' => $rules->lootLuck($tier)];
    }
}
