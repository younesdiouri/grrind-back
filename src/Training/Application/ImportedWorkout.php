<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\WorkoutSource;
use DateTimeImmutable;

/**
 * Une séance candidate à l'import, telle qu'elle arrive du fournisseur.
 *
 * Le jumeau de {@see \App\Training\UI\Http\Request\ImportedWorkoutRequest}, moins les
 * contraintes de format. La duplication est voulue et suit ce que fait déjà `Identity` :
 * le DTO de requête décrit ce qu'un client HTTP a le droit d'envoyer, la commande décrit
 * ce que le métier consomme. Les faire coïncider ferait dépendre `Application` de `UI`, et
 * l'import n'arrivera pas éternellement par HTTP.
 *
 * Ce n'est **pas** un workout : il n'a pas de discipline — la traduction est serveur — et
 * pas de durée, qui se dérive des bornes.
 */
final readonly class ImportedWorkout
{
    public function __construct(
        public string $externalId,
        public WorkoutSource $source,
        public string $activityType,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $endedAt,
        public ?int $distanceMeters = null,
        public ?int $calories = null,
        public ?int $elevationGainMeters = null,
        public ?int $averageHeartRate = null,
    ) {
    }

    /**
     * La durée telle que les bornes du fournisseur la donnent, plancher à zéro — même
     * calcul que dans l'agrégat, parce que l'arbitrage a besoin de la connaître **avant**
     * de décider s'il écrit un workout.
     */
    public function durationSeconds(): int
    {
        return max(0, $this->endedAt->getTimestamp() - $this->startedAt->getTimestamp());
    }

    /**
     * Deux créneaux qui se recouvrent, bornes exclues : une séance qui finit à 8 h 00 et
     * une qui commence à 8 h 00 s'enchaînent, elles ne se recouvrent pas.
     */
    public function overlaps(DateTimeImmutable $startedAt, DateTimeImmutable $endedAt): bool
    {
        return $this->startedAt < $endedAt && $this->endedAt > $startedAt;
    }

    /**
     * Combien de mesures la montre a rendues. C'est ce qui départage deux enregistrements
     * du même effort : entre l'entrée d'Apple Exercice et celle de Strava, on garde celle
     * qui en dit le plus au joueur.
     */
    public function measurementCount(): int
    {
        return \count(array_filter(
            [$this->distanceMeters, $this->calories, $this->elevationGainMeters, $this->averageHeartRate],
            static fn (?int $measurement): bool => null !== $measurement,
        ));
    }
}
