<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure;

use App\Shared\Application\LeagueScores;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/** Une seule somme batch : crédits, sport ensemble et contreparties gardent leur date sportive. */
final readonly class LedgerLeagueScores implements LeagueScores
{
    public function __construct(private Connection $connection)
    {
    }

    public function between(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        /** @var array<string, int|string> $rows */
        $rows = $this->connection->fetchAllKeyValue('SELECT user_id, GREATEST(0, SUM(amount)) FROM xp_transaction WHERE occurred_at >= :start AND occurred_at < :end GROUP BY user_id', ['start' => $start->format('c'), 'end' => $end->format('c')]);

        return array_map(static fn (int|string $score): int => (int) $score, $rows);
    }
}
