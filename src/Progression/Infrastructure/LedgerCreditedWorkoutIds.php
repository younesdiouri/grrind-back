<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure;

use App\Shared\Application\CreditedWorkoutIds;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/** Même critère que la jauge Alam : durée nette positive, indépendamment du montant d'XP. */
final readonly class LedgerCreditedWorkoutIds implements CreditedWorkoutIds
{
    public function __construct(private Connection $connection)
    {
    }

    public function among(array $workoutIds): array
    {
        if ([] === $workoutIds) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT source_id FROM xp_transaction WHERE source_id IN (:ids) GROUP BY source_id HAVING SUM(duration_seconds) > 0',
            ['ids' => $workoutIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys($ids, true);
    }
}
