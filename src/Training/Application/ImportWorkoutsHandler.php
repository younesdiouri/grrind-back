<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\ActivityTypeMap;
use App\Training\Domain\ImportSkipReason;
use App\Training\Domain\Workout;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use Psr\Clock\ClockInterface;

/**
 * Écrit ce qu'un fournisseur santé a rapporté, et dit ce qu'il a écarté.
 *
 * **Un import est un ensemble, pas une transaction tout-ou-rien.** Neuf séances valides ne
 * peuvent pas échouer parce que la dixième est un doublon : chaque candidat est tranché
 * pour lui-même, et le lot rend les deux listes. C'est la différence entre une API de
 * synchronisation et une API de création.
 *
 * Ce que le workout **vaut** ne se décide pas ici : ni XP (#89, #90), ni fenêtre
 * d'antériorité, ni chevauchement, ni plancher de durée (#91). Ce handler écrit des faits.
 *
 * **Il n'y a pas encore de transaction, et c'est visible.** Entre la lecture des doublons
 * et le `flush`, deux synchronisations concurrentes du même compte passent toutes les
 * deux ; la base refuse alors la seconde par `uniq_workout_external`, et le lot entier
 * échoue au lieu du seul doublon. Le verrou qui sérialise les imports d'un joueur arrive au
 * #89 avec le reste de la transaction — l'écrire ici obligerait à le réécrire là-bas.
 */
final readonly class ImportWorkoutsHandler
{
    public function __construct(
        private WorkoutRepository $workouts,
        private ActivityTypeMap $activityTypes,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ImportWorkouts $command): WorkoutImport
    {
        $now = $this->clock->now();
        $known = $this->workouts->knownProviderKeys(
            $command->userId,
            array_map(static fn (ImportedWorkout $candidate): string => $candidate->externalId, $command->workouts),
        );

        $imported = [];
        $skipped = [];

        foreach ($command->workouts as $candidate) {
            $key = WorkoutRepository::providerKey($candidate->source, $candidate->externalId);

            if (isset($known[$key])) {
                $skipped[] = new SkippedWorkout($candidate->externalId, $candidate->activityType, ImportSkipReason::AlreadyImported);

                continue;
            }

            // Le lot est une source de doublons au même titre que la base : un client qui
            // concatène deux pages de HealthKit peut envoyer deux fois la même séance, et
            // sans cette ligne c'est la contrainte d'unicité qui le découvrirait — en
            // faisant échouer les neuf autres.
            $known[$key] = true;

            $discipline = $this->activityTypes->disciplineFor($candidate->source->value, $candidate->activityType);

            if (null === $discipline) {
                $skipped[] = new SkippedWorkout($candidate->externalId, $candidate->activityType, ImportSkipReason::UnsupportedActivity);

                continue;
            }

            $workout = Workout::record(
                userId: $command->userId,
                discipline: $discipline,
                source: $candidate->source,
                startedAt: $candidate->startedAt,
                endedAt: $candidate->endedAt,
                now: $now,
                externalId: $candidate->externalId,
                distanceMeters: $candidate->distanceMeters,
                calories: $candidate->calories,
                elevationGainMeters: $candidate->elevationGainMeters,
                averageHeartRate: $candidate->averageHeartRate,
            );

            $this->workouts->add($workout);
            $imported[] = $workout;
        }

        $this->workouts->commit();

        return new WorkoutImport($imported, $skipped);
    }
}
