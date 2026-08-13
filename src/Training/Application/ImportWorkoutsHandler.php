<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Application\SessionRewards;
use App\Shared\Domain\Activity\ActivityTypeMap;
use App\Shared\Domain\Event\WorkoutImported;
use App\Training\Domain\ImportSkipReason;
use App\Training\Domain\Workout;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * La transaction d'import : un verrou, N workouts, l'ordre chronologique.
 *
 * ————— Pourquoi une transaction et pas N appels ————————————————————————————————————————
 *
 * Créditer dix workouts en créditant dix fois serait faux à trois titres : dix verrous pris
 * et relâchés, donc dix fenêtres de course ; dix récompenses que le client devrait recoller
 * lui-même ; et un échec au septième qui laisserait six crédits en base et le joueur avec
 * la moitié de sa récompense, sans moyen de rejouer proprement.
 *
 * **Un seul verrou.** Il est posé par `SessionRewards` au premier workout — un verrou de
 * ligne est ré-entrant dans une transaction — et tenu jusqu'au COMMIT. C'est lui qui
 * sérialise deux synchronisations concurrentes : deux appareils du même compte, ou l'app
 * relancée pendant qu'une synchronisation tournait encore.
 *
 * ————— L'ordre chronologique n'est pas cosmétique ——————————————————————————————————————
 *
 * Les rendements décroissants et le plafond quotidien se calculent sur ce que le joueur a
 * **déjà** fait ce jour-là. Créditer le vélo de 18 h avant la course de 7 h donnerait un
 * autre total, et deux imports du même lot dans un ordre différent donneraient deux ledgers
 * différents — pour la même journée de sport.
 *
 * Le piège est en aval : la charge du jour doit être **relue à chaque itération**, en voyant
 * les workouts crédités plus tôt dans la même boucle et pas seulement ce qui était en base
 * au BEGIN. C'est bien ce qui se passe, et ça se joue en deux endroits : `GrantXpHandler`
 * appelle `DailyLoadProvider` par workout, et `flush` avant de relire — donc la requête voit
 * les lignes de la transaction en cours.
 *
 * ————— Un import est un ensemble, pas une transaction tout-ou-rien ——————————————————————
 *
 * Les deux phrases coexistent, et ce n'est pas contradictoire. Un workout **écarté** —
 * doublon, activité non traduite — n'annule rien : neuf séances valides ne peuvent pas
 * échouer parce que la dixième est une partie de curling. Une **panne**, elle, défait tout :
 * un workout écrit sans XP créditée est une perte silencieuse.
 */
final readonly class ImportWorkoutsHandler
{
    public function __construct(
        private WorkoutRepository $workouts,
        private ActivityTypeMap $activityTypes,
        private SessionRewards $rewards,
        private MessageBusInterface $events,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ImportWorkouts $command): WorkoutImport
    {
        $now = $this->clock->now();

        // Trié avant d'ouvrir la transaction : c'est du calcul, il n'a rien à faire sous le
        // verrou. Un tri stable, donc deux workouts commencés à la même seconde gardent
        // l'ordre du client plutôt qu'un ordre arbitraire — PHP le garantit depuis 8.0.
        $candidates = $command->workouts;
        usort(
            $candidates,
            static fn (ImportedWorkout $left, ImportedWorkout $right): int => $left->startedAt <=> $right->startedAt,
        );

        return $this->workouts->transactional(fn (): WorkoutImport => $this->credit($command, $candidates, $now));
    }

    /**
     * @param list<ImportedWorkout> $candidates triés par `startedAt` croissant
     */
    private function credit(ImportWorkouts $command, array $candidates, DateTimeImmutable $now): WorkoutImport
    {
        $known = $this->workouts->knownProviderKeys(
            $command->userId,
            array_map(static fn (ImportedWorkout $candidate): string => $candidate->externalId, $candidates),
        );

        $imported = [];
        $skipped = [];

        foreach ($candidates as $candidate) {
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
            // Écrit avant de créditer, et dans la même transaction : le ledger référence
            // l'identifiant du workout, et une écriture d'XP qui pointe une ligne encore
            // absente serait une piste d'audit qui ne mène nulle part le temps d'un COMMIT.
            $this->workouts->commit();

            $fact = self::factOf($workout);
            $imported[] = new SessionCompletion($workout, $this->rewards->creditFor($fact));

            // Publié dans la transaction : le transport Doctrine écrit dans
            // `messenger_messages` sur la même connexion, donc l'événement partage le COMMIT.
            // Un import en publie N — le classement compte des activités, pas des
            // synchronisations.
            $this->events->dispatch($fact);
        }

        return new WorkoutImport($imported, $skipped);
    }

    /**
     * Le fait publié, et celui que `Progression` crédite : le même objet, à dessein. Une
     * seconde forme des mêmes champs finirait par en diverger d'un.
     */
    private static function factOf(Workout $workout): WorkoutImported
    {
        return new WorkoutImported(
            $workout->id(),
            $workout->userId(),
            $workout->discipline(),
            $workout->startedAt(),
            $workout->endedAt(),
            $workout->durationSeconds(),
            $workout->source(),
            $workout->trust(),
        );
    }
}
