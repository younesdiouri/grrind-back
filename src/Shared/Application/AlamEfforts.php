<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;

/** Training fournit les bornes et métriques de sources déjà autorisées par le ledger Progression. */
interface AlamEfforts
{
    /**
     * @param list<string> $sourceIds
     *
     * @return list<AlamEffort> */
    public function within(array $sourceIds, DateTimeImmutable $start, DateTimeImmutable $end, GameRulesets $rules): array;
}
