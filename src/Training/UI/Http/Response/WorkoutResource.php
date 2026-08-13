<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Domain\Workout;
use DateTimeInterface;

/**
 * Représentation publique d'un workout, séparée de l'entité pour qu'un champ ajouté au
 * domaine ne parte pas sur le réseau par accident.
 *
 * **La seule forme d'un workout dans toute l'API** : l'historique la sert, et le
 * `SyncSummary` l'embarque telle quelle plutôt que de la réécrire à plat. Un client décode
 * un seul type.
 *
 * Tous les champs sont toujours présents, jamais omis — mais les mesures sont **nullables**,
 * et c'est structurel : aucun appareil ne fournit tout. `null` veut dire « non mesuré »,
 * jamais zéro ; un tour de piste plat a bien un dénivelé de zéro. Un champ qui apparaît et
 * disparaît finit lu de travers, alors qu'un `null` explicite se teste.
 *
 * `durationSeconds` est la durée **réellement mesurée**, pas celle qui a été payée : au-delà
 * du plafond, l'XP est calculée sur une durée écrêtée mais l'historique dit ce qui s'est
 * passé. Le détail du calcul, lui, est dans le breakdown.
 */
final readonly class WorkoutResource
{
    public function __construct(
        public string $id,
        public string $discipline,
        public string $source,
        public string $trust,
        public string $startedAt,
        public string $endedAt,
        public int $durationSeconds,
        public ?int $distanceMeters,
        public ?int $calories,
        public ?int $elevationGainMeters,
        public ?int $averageHeartRate,
        public ?string $externalId,
    ) {
    }

    public static function from(Workout $workout): self
    {
        return new self(
            $workout->id()->toRfc4122(),
            $workout->discipline()->value,
            $workout->source()->value,
            $workout->trust()->value,
            $workout->startedAt()->format(DateTimeInterface::ATOM),
            $workout->endedAt()->format(DateTimeInterface::ATOM),
            $workout->durationSeconds(),
            $workout->distanceMeters(),
            $workout->calories(),
            $workout->elevationGainMeters(),
            $workout->averageHeartRate(),
            $workout->externalId(),
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
            'source' => $this->source,
            'trust' => $this->trust,
            'startedAt' => $this->startedAt,
            'endedAt' => $this->endedAt,
            'durationSeconds' => $this->durationSeconds,
            'distanceMeters' => $this->distanceMeters,
            'calories' => $this->calories,
            'elevationGainMeters' => $this->elevationGainMeters,
            'averageHeartRate' => $this->averageHeartRate,
            // L'identifiant chez le fournisseur, rendu au client parce que c'est lui qui
            // l'a envoyé : il peut ainsi rapprocher sa liste HealthKit de ce que le serveur
            // connaît déjà, sans deviner.
            'externalId' => $this->externalId,
        ];
    }
}
