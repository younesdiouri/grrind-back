<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Domain\Activity\Discipline;
use App\Training\Domain\ImportSkipReason;

/** Même arbitrage pur pour un import réel et un programme fictif, avant toute écriture. */
final class WorkoutOverlapArbitration
{
    /**
     * Deuxième passe : deux candidats qui se recouvrent décrivent **le même effort vu par
     * deux applications**, et un seul survit.
     *
     * **Le plus complet gagne**, pas le premier arrivé. Apple Exercice et Strava ne
     * démarrent jamais à la même seconde ; garder celui qui commence en premier reviendrait
     * à tirer au sort, et à laisser parfois gagner l'enregistrement qui ne porte ni distance
     * ni cardio. Le joueur y perdrait une ligne d'animation pour une raison qu'on ne
     * saurait pas lui expliquer.
     *
     * Le départage est **total et déterministe** — mesures, puis durée, puis identifiant —
     * parce que deux imports du même lot doivent produire le même ledger, quel que soit
     * l'ordre dans lequel le client a empilé ses pages.
     *
     * @param list<array{ImportedWorkout, Discipline}> $eligible triés par `startedAt` croissant
     * @param list<SkippedWorkout>                     $skipped
     *
     * @return list<array{ImportedWorkout, Discipline}>
     */
    public static function retain(array $eligible, array &$skipped): array
    {
        $survivors = [];

        foreach ($eligible as $entry) {
            [$candidate] = $entry;

            $rivalIndex = null;

            foreach ($survivors as $index => [$survivor]) {
                if ($candidate->overlaps($survivor->startedAt, $survivor->endedAt)) {
                    $rivalIndex = $index;

                    break;
                }
            }

            if (null === $rivalIndex) {
                $survivors[] = $entry;

                continue;
            }

            [$rival] = $survivors[$rivalIndex];

            if (self::isRicherThan($candidate, $rival)) {
                $skipped[] = new SkippedWorkout($rival->externalId, $rival->activityType, ImportSkipReason::Overlaps);
                $survivors[$rivalIndex] = $entry;

                continue;
            }

            $skipped[] = new SkippedWorkout($candidate->externalId, $candidate->activityType, ImportSkipReason::Overlaps);
        }

        return array_values($survivors);
    }

    /**
     * Qui gagne, entre deux enregistrements du même effort. D'abord celui qui en dit le
     * plus au joueur, puis le plus long — une application qui coupe l'échauffement décrit
     * moins bien la séance — puis l'identifiant, qui ne veut rien dire mais qui tranche.
     *
     * Ce dernier critère n'est pas de la coquetterie : sans lui, deux candidats
     * rigoureusement équivalents se départageraient par l'ordre du tableau, et le même lot
     * envoyé dans un autre ordre écrirait un autre ledger.
     */
    private static function isRicherThan(ImportedWorkout $candidate, ImportedWorkout $rival): bool
    {
        $merits = static fn (ImportedWorkout $workout): array => [$workout->measurementCount(), $workout->durationSeconds()];

        if ($merits($candidate) !== $merits($rival)) {
            return $merits($candidate) > $merits($rival);
        }

        return $candidate->externalId < $rival->externalId;
    }
}
