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
 * **Un import en publie N, jamais un agrégat.** Le classement compte des activités, pas des
 * synchronisations : un joueur qui rentre de vacances avec dix séances a fait dix séances, et
 * un abonné qui recevrait « une synchronisation de dix » devrait défaire l'agrégation pour
 * retrouver ce qui l'intéresse.
 *
 * `durationSeconds` est la durée **retenue** et non `endedAt - startedAt` : un abonné qui la
 * recalculerait ignorerait l'écrêtage. Les deux dates servent à l'affichage et au fuseau,
 * pas à refaire le calcul.
 *
 * Pas d'écart : un workout ignoré ou refusé n'a rien appris à personne, il n'y a pas de fait.
 */
final readonly class WorkoutImported implements DomainEvent
{
    public function __construct(
        public Uuid $workoutId,
        public Uuid $userId,
        public Discipline $discipline,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endedAt,
        public int $durationSeconds,
        public WorkoutSource $source,
        public TrustLevel $trust,
    ) {
    }

    /**
     * L'instant du **sport**, pas celui de l'import. C'est le cas que l'interface
     * annonçait : les deux coïncidaient tant que Grrind tenait le chronomètre, et l'import
     * les a fait diverger de plusieurs jours.
     *
     * `startedAt` et non `endedAt` : c'est la borne qui rattache un workout à une journée,
     * et une séance à cheval sur minuit ne doit compter que dans une seule — celle où le
     * joueur a commencé.
     */
    public function occurredAt(): DateTimeImmutable
    {
        return $this->startedAt;
    }
}
