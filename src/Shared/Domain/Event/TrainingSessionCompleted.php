<?php

declare(strict_types=1);

namespace App\Shared\Domain\Event;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Une séance a été menée à son terme. C'est le fait dont tout le jeu découle : l'XP, le
 * loot, le streak, les classements — aucun de ces modules n'est nommé dans `Training`,
 * ils apprennent la nouvelle ici.
 *
 * Le payload est autoportant, et `durationSeconds` en est la raison d'être : c'est la
 * durée **retenue**, écrêtée au plafond, et non `endedAt - startedAt`. Un abonné qui la
 * recalculerait recréditerait le chronomètre oublié. Les deux dates sont là pour
 * l'affichage et le fuseau, pas pour refaire le calcul.
 *
 * `source` et `trust` voyagent dès la v1 alors qu'ils ne valent que `MANUAL_TIMER` /
 * `DECLARED` : le jour où Strava arrive, une séance vérifiée pourra rapporter davantage
 * sans que ce contrat bouge.
 *
 * Pas de statut : un abandon ne produit pas d'événement. Il n'y a rien à apprendre à
 * qui que ce soit d'une séance qui ne compte pas.
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
        public SessionSource $source,
        public TrustLevel $trust,
    ) {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->endedAt;
    }
}
