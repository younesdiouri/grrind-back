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
}
