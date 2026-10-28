<?php

declare(strict_types=1);

namespace App\Training\Infrastructure;

use App\Shared\Application\CreditedWorkoutIds;
use App\Shared\Application\WorkoutCounts;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Deux lectures batch, quelle que soit la taille de la guilde. On relit le crédit réellement
 * enregistré, jamais les règles d'import actuelles : un rééquilibrage ne réécrit pas la semaine.
 * Aucun montant ni plafond d'XP n'entre dans le compte.
 */
final readonly class CreditedWorkoutCounts implements WorkoutCounts
{
    public function __construct(private Connection $connection, private CreditedWorkoutIds $credits)
    {
    }

    public function since(array $since, DateTimeImmutable $until): array
    {
        if ([] === $since) {
            return [];
        }

        /** @var list<array{id: string, user_id: string, ended_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, user_id, ended_at FROM workout WHERE user_id IN (:players) AND ended_at >= :start AND ended_at < :until',
            ['players' => array_keys($since), 'start' => min($since)->format('c'), 'until' => $until->format('c')],
            ['players' => ArrayParameterType::STRING],
        );
        $rows = array_values(array_filter($rows, static fn (array $row): bool => new DateTimeImmutable($row['ended_at']) >= $since[$row['user_id']]));
        $credited = $this->credits->among(array_column($rows, 'id'));
        $counts = [];
        foreach ($rows as $row) {
            if (isset($credited[$row['id']])) {
                $counts[$row['user_id']] = ($counts[$row['user_id']] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
