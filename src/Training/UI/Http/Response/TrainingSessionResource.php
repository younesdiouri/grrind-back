<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Domain\TrainingSession;
use DateTimeInterface;

/**
 * Représentation publique d'une séance, séparée de l'entité pour qu'un champ ajouté au
 * domaine ne parte pas sur le réseau par accident.
 *
 * Tous les champs sont toujours présents, jamais omis — `source` et `trust` alors qu'ils
 * ne valent encore que `MANUAL_TIMER` / `DECLARED`, `endedAt` et `durationSeconds` à
 * `null` tant que la séance court. Une séance ouverte et une séance close sont **une
 * seule forme** : le client décode un seul type, et un champ qui apparaît et
 * disparaît finit lu de travers.
 *
 * Ce que la complétion rapporte décrit une *récompense*, pas une séance : ça vit dans
 * {@see RewardSummaryResource}, qui embarque cette forme-ci telle quelle plutôt que de la
 * réécrire à plat — une séance close se décode partout avec le même type.
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
