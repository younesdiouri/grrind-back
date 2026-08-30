<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Application\SessionDrops;
use App\Shared\Application\SessionRewards;
use App\Shared\Domain\Activity\ActivityTypeMap;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Event\WorkoutImported;
use App\Training\Domain\ImportSkipReason;
use App\Training\Domain\Workout;
use App\Training\Domain\WorkoutRules;
use App\Training\Infrastructure\Doctrine\WorkoutRepository;
use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * La transaction d'import : un verrou, N workouts, l'ordre chronologique, et l'arbitrage.
 *
 * ————— Pourquoi une transaction et pas N appels ————————————————————————————————————————
 *
 * Créditer dix workouts en créditant dix fois serait faux à trois titres : dix verrous pris
 * et relâchés, donc dix fenêtres de course ; dix récompenses que le client devrait recoller
 * lui-même ; et un échec au septième qui laisserait six crédits en base et le joueur avec
 * la moitié de sa récompense, sans moyen de rejouer proprement.
 *
 * **Un seul verrou.** Il est posé par `SessionRewards` au premier workout crédité — un
 * verrou de ligne est ré-entrant dans une transaction — et tenu jusqu'au COMMIT. C'est lui
 * qui sérialise deux synchronisations concurrentes : deux appareils du même compte, ou
 * l'app relancée pendant qu'une synchronisation tournait encore.
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
 * ————— Trois passes, et pas une boucle unique ——————————————————————————————————————————
 *
 * 1. **le tri au mérite** : chaque candidat est jugé pour lui-même — doublon, activité non
 *    traduite, durée sous le plancher. Ceux qui tombent ici n'existent pas pour la suite ;
 * 2. **les chevauchements internes** : parmi les rescapés, ceux qui se recouvrent décrivent
 *    le même effort vu par deux applications, et un seul survit ;
 * 3. **l'écriture**, dans l'ordre chronologique, sous le verrou.
 *
 * Une boucle unique ne saurait pas faire la deuxième : décider lequel de deux enregistrements
 * concurrents on garde demande de les avoir tous les deux sous les yeux.
 *
 * ————— Un import est un ensemble, pas une transaction tout-ou-rien ——————————————————————
 *
 * Les deux phrases coexistent, et ce n'est pas contradictoire. Un workout **écarté** —
 * doublon, activité non traduite, chevauchement — n'annule rien : neuf séances valides ne
 * peuvent pas échouer parce que la dixième est une partie de curling. Une **panne**, elle,
 * défait tout : un workout écrit sans XP créditée est une perte silencieuse.
 */
final readonly class ImportWorkoutsHandler
{
    public function __construct(
        private WorkoutRepository $workouts,
        private ActivityTypeMap $activityTypes,
        private WorkoutRules $rules,
        private SessionRewards $rewards,
        private SessionDrops $drops,
        // `event.bus` explicitement (#155) : `WorkoutImported` est un `DomainEvent`, il
        // part sur le bus qui tolère l'absence d'abonné. Sans `#[Target]`, l'autowiring
        // par nom de paramètre est déprécié en 8.1 et retomberait de toute façon sur
        // `default_bus` — le bus strict, celui qu'on cherche justement à éviter ici.
        #[Target('event.bus')]
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
        $skipped = [];

        $eligible = $this->onTheirOwnMerits($command, $candidates, $skipped);
        $eligible = self::withoutInternalOverlaps($eligible, $skipped);

        return new WorkoutImport($now, $this->write($command, $eligible, $now, $skipped), $skipped);
    }

    /**
     * Première passe : ce qui se juge sans regarder les autres candidats.
     *
     * @param list<ImportedWorkout> $candidates
     * @param list<SkippedWorkout>  $skipped    accumulé par référence, dans l'ordre chronologique
     *
     * @return list<array{ImportedWorkout, Discipline}>
     */
    private function onTheirOwnMerits(ImportWorkouts $command, array $candidates, array &$skipped): array
    {
        $known = $this->workouts->knownProviderKeys(
            $command->userId,
            array_map(static fn (ImportedWorkout $candidate): string => $candidate->externalId, $candidates),
        );

        $eligible = [];

        foreach ($candidates as $candidate) {
            $key = WorkoutRepository::providerKey($candidate->source, $candidate->externalId);

            if (isset($known[$key])) {
                $skipped[] = self::skip($candidate, ImportSkipReason::AlreadyImported);

                continue;
            }

            // Le lot est une source de doublons au même titre que la base : un client qui
            // concatène deux pages de HealthKit peut envoyer deux fois la même séance, et
            // sans cette ligne c'est la contrainte d'unicité qui le découvrirait — en
            // faisant échouer les neuf autres.
            $known[$key] = true;

            $discipline = $this->activityTypes->disciplineFor($candidate->source->value, $candidate->activityType);

            if (null === $discipline) {
                $skipped[] = self::skip($candidate, ImportSkipReason::UnsupportedActivity);

                continue;
            }

            // Écarté avant d'être écrit : une séance de douze secondes n'a pas eu lieu du
            // point de vue du joueur, et lui laisser une ligne d'historique qu'il n'a pas
            // vécue serait pire que de ne rien dire.
            if ($this->rules->isTooShort($candidate->durationSeconds())) {
                $skipped[] = self::skip($candidate, ImportSkipReason::TooShort);

                continue;
            }

            $eligible[] = [$candidate, $discipline];
        }

        return $eligible;
    }

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
    private static function withoutInternalOverlaps(array $eligible, array &$skipped): array
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
                $skipped[] = self::skip($rival, ImportSkipReason::Overlaps);
                $survivors[$rivalIndex] = $entry;

                continue;
            }

            $skipped[] = self::skip($candidate, ImportSkipReason::Overlaps);
        }

        return array_values($survivors);
    }

    /**
     * Troisième passe : l'écriture, dans l'ordre chronologique et sous le verrou.
     *
     * @param list<array{ImportedWorkout, Discipline}> $eligible
     * @param list<SkippedWorkout>                     $skipped
     *
     * @return list<SessionCompletion>
     */
    private function write(ImportWorkouts $command, array $eligible, DateTimeImmutable $now, array &$skipped): array
    {
        if ([] === $eligible) {
            return [];
        }

        $busy = $this->occupiedIntervals($command, $eligible);
        $imported = [];

        foreach ($eligible as [$candidate, $discipline]) {
            foreach ($busy as [$startedAt, $endedAt]) {
                if ($candidate->overlaps($startedAt, $endedAt)) {
                    $skipped[] = self::skip($candidate, ImportSkipReason::Overlaps);

                    continue 2;
                }
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

            // Le workout occupe désormais son créneau, y compris s'il n'est pas crédité :
            // un chevauchement reste un chevauchement, et rien ne dit que le doublon d'une
            // séance archivée arrivera dans le même lot qu'elle.
            $busy[] = [$candidate->startedAt, $candidate->endedAt];

            // **Le garde-fou du virage.** Trois ans d'Apple Health crédités d'un coup
            // amèneraient le joueur au niveau 60 avant sa première course *pour* Grrind, et
            // le ledger étant append-only, ça ne se rattrape pas. Il retrouve donc son passé
            // sans le monnayer : la ligne est là, l'historique est là, l'XP non.
            if (!$this->rules->isWithinWindow($candidate->endedAt, $now)) {
                $skipped[] = self::skip($candidate, ImportSkipReason::OutOfWindow);

                continue;
            }

            $fact = $this->factOf($workout);
            $reward = $this->rewards->creditFor($fact);
            // Le loot après l'XP et les titres, avant l'outbox — l'ordre de
            // `ARCHITECTURE.md`. `$reward` porte déjà le niveau d'après ce crédit et le
            // verdict « créditée ou non » : voir le docblock de `SessionDrops` pour
            // pourquoi cette classe ne repose aucune de ces deux questions.
            $imported[] = new SessionCompletion($workout, $reward, $this->drops->rollFor($fact, $reward));

            // Publié dans la transaction : le transport Doctrine écrit dans
            // `messenger_messages` sur la même connexion, donc l'événement partage le COMMIT.
            // Un import en publie N — le classement compte des activités, pas des
            // synchronisations — et rien pour ce qui n'a pas été crédité.
            $this->events->dispatch($fact);
        }

        return $imported;
    }

    /**
     * Les créneaux déjà pris en base autour de la période du lot.
     *
     * La fenêtre interrogée déborde du lot du plafond de durée : un workout déjà stocké qui
     * commence avant le premier candidat peut très bien finir après lui, et une requête
     * calée sur les seules bornes du lot ne le verrait pas.
     *
     * @param non-empty-list<array{ImportedWorkout, Discipline}> $eligible triés par `startedAt` croissant
     *
     * @return list<array{DateTimeImmutable, DateTimeImmutable}>
     */
    private function occupiedIntervals(ImportWorkouts $command, array $eligible): array
    {
        $margin = \sprintf('%d seconds', $this->rules->maximumDurationSeconds);
        $latest = $eligible[array_key_last($eligible)][0];

        return $this->workouts->busyIntervalsBetween(
            $command->userId,
            $eligible[0][0]->startedAt->modify('-'.$margin),
            $latest->endedAt->modify('+'.$margin),
        );
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

    private static function skip(ImportedWorkout $candidate, ImportSkipReason $reason): SkippedWorkout
    {
        return new SkippedWorkout($candidate->externalId, $candidate->activityType, $reason);
    }

    /**
     * Le fait publié, et celui que `Progression` crédite : le même objet, à dessein. Une
     * seconde forme des mêmes champs finirait par en diverger d'un.
     *
     * `durationSeconds` est la durée **retenue**, écrêtée au plafond — pas celle du
     * workout. Le workout garde ce que la montre a mesuré ; l'événement porte ce qu'on
     * accepte d'en payer, et un abonné qui recalculerait `endedAt - startedAt` recréditerait
     * l'enregistrement oublié sur la montre.
     */
    private function factOf(Workout $workout): WorkoutImported
    {
        return new WorkoutImported(
            $workout->id(),
            $workout->userId(),
            $workout->discipline(),
            $workout->startedAt(),
            $workout->endedAt(),
            $this->rules->retainedDuration($workout->durationSeconds()),
            $workout->source(),
            $workout->trust(),
            $workout->distanceMeters(),
            $workout->elevationGainMeters(),
        );
    }
}
