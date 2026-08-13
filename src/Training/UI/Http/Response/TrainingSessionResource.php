<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Response;

use App\Training\Domain\Workout;
use DateTimeInterface;

/**
 * Représentation publique d'une séance, séparée de l'entité pour qu'un champ ajouté au
 * domaine ne parte pas sur le réseau par accident.
 *
 * Tous les champs sont toujours présents, jamais omis. Il n'y a plus de `status` : un
 * workout est un fait passé, il n'a pas d'état — et `endedAt` comme `durationSeconds`
 * ne sont plus nullables pour la même raison. Un champ qui apparaît et disparaît finit
 * lu de travers, un champ qui ne peut plus être nul ment en restant optionnel.
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
        public string $source,
        public string $trust,
        public string $startedAt,
        public string $endedAt,
        public int $durationSeconds,
    ) {
    }

    public static function from(Workout $session): self
    {
        return new self(
            $session->id()->toRfc4122(),
            $session->discipline()->value,
            $session->source()->value,
            $session->trust()->value,
            $session->startedAt()->format(DateTimeInterface::ATOM),
            $session->endedAt()->format(DateTimeInterface::ATOM),
            $session->durationSeconds(),
        );
    }

    /**
     * @return array<string, string|int>
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
        ];
    }
}
