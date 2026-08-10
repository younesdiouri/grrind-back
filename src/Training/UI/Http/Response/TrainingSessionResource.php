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
 * Ce que la clôture produira — durée, XP, loot — n'a pas sa place ici : à l'ouverture,
 * ces valeurs n'existent pas encore.
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
        );
    }

    /**
     * @return array<string, string>
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
        ];
    }
}
