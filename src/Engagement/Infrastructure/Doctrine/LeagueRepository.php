<?php

declare(strict_types=1);

namespace App\Engagement\Infrastructure\Doctrine;

use App\Engagement\Domain\LeagueTier;
use App\Engagement\Domain\LeagueWeek;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** DBAL garde les lectures batch et le verrou de clôture explicites ; aucune entité d'un autre module. */
final readonly class LeagueRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function open(LeagueWeek $week): void
    {
        $this->connection->executeStatement('INSERT INTO engagement_league_period (starts_at, ends_at) VALUES (?, ?) ON CONFLICT DO NOTHING', [$week->startsAt->format('c'), $week->endsAt->format('c')]);
    }

    /** @return array{starts_at: string, ends_at: string}|null */
    public function lockOpenPeriod(): ?array
    {
        /** @var array{starts_at: string, ends_at: string}|false $row */
        $row = $this->connection->fetchAssociative('SELECT starts_at, ends_at FROM engagement_league_period WHERE settled_at IS NULL ORDER BY starts_at LIMIT 1 FOR UPDATE');

        return false === $row ? null : $row;
    }

    /**
     * @param list<Uuid>|null $players null = tous les participants ; [] = personne
     *
     * @return array<string, LeagueTier>
     */
    public function tiersBefore(DateTimeImmutable $start, ?array $players = null): array
    {
        if ([] === $players) {
            return [];
        }
        $parameters = ['start' => $start->format('c')];
        $types = [];
        $filter = '';
        if (null !== $players) {
            $filter = ' AND player_id IN (:players)';
            $parameters['players'] = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $players);
            $types['players'] = ArrayParameterType::STRING;
        }
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue('SELECT DISTINCT ON (player_id) player_id, next_tier FROM engagement_league_result WHERE starts_at < :start'.$filter.' ORDER BY player_id, starts_at DESC', $parameters, $types);

        return array_map(LeagueTier::from(...), $rows);
    }

    /** @param array<string, array{tier: LeagueTier, score: int, rank: ?int, participantCount: int, nextTier: LeagueTier, movement: \App\Engagement\Domain\LeagueMovement}> $results */
    public function settle(LeagueWeek $week, array $results, DateTimeImmutable $now, string $version): void
    {
        foreach ($results as $id => $result) {
            $this->connection->insert('engagement_league_result', ['player_id' => $id, 'starts_at' => $week->startsAt->format('c'), 'tier' => $result['tier']->value, 'next_tier' => $result['nextTier']->value, 'score' => $result['score'], 'rank' => $result['rank'], 'participant_count' => $result['participantCount']]);
        }
        $this->connection->executeStatement('UPDATE engagement_league_period SET settled_at = ?, ruleset_version = ? WHERE starts_at = ?', [$now->format('c'), $version, $week->startsAt->format('c')]);
        $this->open(LeagueWeek::containing($week->endsAt));
    }

    /** @return array{starts_at: string, ends_at: string, settled_at: string, tier: string, next_tier: string, score: int, rank: ?int, participant_count: int, ruleset_version: string}|null */
    public function lastResult(Uuid $player, DateTimeImmutable $start): ?array
    {
        /** @var array{starts_at: string, ends_at: string, settled_at: string, tier: string, next_tier: string, score: int, rank: ?int, participant_count: int, ruleset_version: string}|false $row */
        $row = $this->connection->fetchAssociative('SELECT r.*, p.ends_at, p.settled_at, p.ruleset_version FROM engagement_league_result r JOIN engagement_league_period p ON p.starts_at = r.starts_at WHERE r.player_id = :player AND r.starts_at < :start ORDER BY r.starts_at DESC LIMIT 1', ['player' => $player->toRfc4122(), 'start' => $start->format('c')]);

        return false === $row ? null : $row;
    }
}
