<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\TrustLevel;
use App\Shared\Domain\Activity\WorkoutSource;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Le fait dont tout le jeu découle. XP, loot, streak, classements : aucun de ces modules
 * n'est nommé dans `Training`, ils apprennent la nouvelle ici.
 *
 * `durationSeconds` est la durée **retenue**, écrêtée au plafond, et non
 * `endedAt - startedAt` : un abonné qui la recalculerait recréditerait le chronomètre
 * oublié. Les deux dates servent à l'affichage, pas à refaire le calcul.
 *
 * Pas de statut : un abandon ne produit pas d'événement, il n'y a rien à apprendre à
 * quiconque d'une séance qui ne compte pas.
 */
final readonly class TrainingSessionCompleted implements DomainEvent
{
    public function __construct(
        public Uuid $sessionId,
        public Uuid $userId,
        public Discipline $discipline,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endedAt,
        public int $durationSeconds,
        public WorkoutSource $source,
        public TrustLevel $trust,
    ) {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->endedAt;
    }
}
