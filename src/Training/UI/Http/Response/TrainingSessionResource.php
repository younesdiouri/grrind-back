<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Domain\TrainingSession;
use DateTimeInterface;

/**
 * Représentation publique d'une séance, séparée de l'entité pour qu'un champ ajouté
 * au domaine ne parte pas sur le réseau par accident.
 *
 * `source` et `trust` sont exposés dès la v1 alors qu'ils ne valent aujourd'hui que
 * `MANUAL_TIMER` / `DECLARED` : le client iOS doit apprendre à les lire maintenant,
 * pour que l'arrivée de Strava n'ajoute pas de champ au contrat.
 *
 * Le même argument vaut pour `endedAt` et `durationSeconds`, `null` tant que la séance
 * court : une séance ouverte et une séance close sont **une seule forme**, décodée par
 * un seul type côté client, plutôt que deux à tenir en phase. Les champs sont toujours
 * présents, jamais omis — un champ qui apparaît et disparaît est un champ qu'on finit
 * par lire de travers.
 *
 * Ce que le Lot 4 ajoutera — XP, level ups, loot, streak — n'a rien à faire ici : ça
 * décrit une *récompense*, pas une séance, et ça partira dans son propre `RewardSummary`.
 */
final readonly class TrainingSessionResource
{
    public function __construct(
        public string $id,
        public string $discipline,
        public string $status,
        public string $source,
        public string $trust,
        public string $startedAt,
        public ?string $endedAt,
        public ?int $durationSeconds,
    ) {
    }

    public static function from(TrainingSession $session): self
    {
        return new self(
            $session->id()->toRfc4122(),
            $session->discipline()->value,
            $session->status()->value,
            $session->source()->value,
            $session->trust()->value,
            $session->startedAt()->format(DateTimeInterface::ATOM),
            $session->endedAt()?->format(DateTimeInterface::ATOM),
            $session->durationSeconds(),
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'discipline' => $this->discipline,
            'status' => $this->status,
            'source' => $this->source,
            'trust' => $this->trust,
            'startedAt' => $this->startedAt,
            'endedAt' => $this->endedAt,
            'durationSeconds' => $this->durationSeconds,
        ];
    }
}
