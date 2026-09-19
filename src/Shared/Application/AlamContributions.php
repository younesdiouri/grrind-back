<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/** Franchit Progression sans importer son ledger ; les bonus ne se soustraient jamais d'un crédit plafonné. */
interface AlamContributions
{
    /**
     * @param list<Uuid> $players
     *
     * @return array<string, array{attributes: array<string, int>, total: int, sessions: int, sports: list<array{discipline: string, sessions: int, durationSeconds: int}>}> */
    public function between(array $players, DateTimeImmutable $start, DateTimeImmutable $end, GameRulesets $rules): array;

    /** @param list<Uuid> $players */
    public function lock(array $players, GameRulesets $rules): void;

    /** @param list<Uuid> $players */
    public function activeCount(array $players, DateTimeImmutable $start, DateTimeImmutable $end): int;
}
