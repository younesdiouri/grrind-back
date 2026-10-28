<?php

declare(strict_types=1);

namespace App\Shared\Application;

use DateTimeImmutable;

/**
 * Lecture batch des séances validées : Community ne connaît ni les workouts de Training
 * ni le ledger de Progression. Les bornes portent sur endedAt, début inclus, fin exclue.
 * Chaque joueur a sa propre borne de début (semaine ou adhésion actuelle).
 */
interface WorkoutCounts
{
    /**
     * @param array<string, DateTimeImmutable> $since UUID joueur → début inclus
     *
     * @return array<string, int> UUID joueur → nombre de séances ; absent vaut zéro
     */
    public function since(array $since, DateTimeImmutable $until): array;
}
