<?php

declare(strict_types=1);

namespace App\Training\Application;

use App\Shared\Application\CreditedWorkoutIds;
use App\Shared\Application\GameRulesets;
use App\Shared\Application\TogetherRewards;
use App\Training\Domain\Together\LocationEvidence;
use App\Training\Domain\Together\TogetherDetector;
use App\Training\Domain\Together\TogetherRules;
use App\Training\Domain\Together\TogetherTrack;
use App\Training\Domain\Workout;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** Preuves en transaction ; aucune valeur de jeu ne vient de l'application. */
final readonly class TogetherTracker
{
    public function __construct(private EntityManagerInterface $em, private GameRulesets $rulesets, private TogetherRewards $rewards, private ClockInterface $clock, private CreditedWorkoutIds $credited)
    {
    }

    public function store(LocationEvidence $batch): ?Workout
    {
        return $this->em->wrapInTransaction(function () use ($batch): ?Workout {
            $this->lock();
            $this->purge();
            if ($batch->endedAt <= $this->clock->now()->modify('-30 days')) {
                throw new GoneHttpException('La fenêtre de preuve de 30 jours est expirée.');
            }
            if ($batch->endedAt > $this->clock->now() || $batch->startedAt >= $batch->endedAt || $batch->endedAt->getTimestamp() - $batch->startedAt->getTimestamp() > 86400) {
                throw new UnprocessableEntityHttpException('Bornes de séance invalides.');
            }
            foreach ($batch->points as $point) {
                if ($point['at'] < $batch->startedAt->getTimestamp() || $point['at'] > $batch->endedAt->getTimestamp()) {
                    throw new UnprocessableEntityHttpException('Position hors de la séance.');
                }
            }
            $existing = $this->em->find(LocationEvidence::class, ['userId' => $batch->userId, 'batchId' => $batch->batchId]);
            if (null !== $existing && $existing->digest !== $batch->digest) {
                throw new ConflictHttpException('Cet identifiant de lot désigne déjà une autre preuve.');
            }
            $workout = $this->em->getRepository(Workout::class)->findOneBy(['userId' => $batch->userId, 'source' => $batch->source, 'externalId' => $batch->externalId]);
            if (null !== $workout && ($workout->startedAt() != $batch->startedAt || $workout->endedAt() != $batch->endedAt)) {
                throw new UnprocessableEntityHttpException('Les bornes diffèrent du workout importé.');
            }
            if (null === $existing) {
                $all = $this->evidenceFor($batch->userId, $batch->source, $batch->externalId);
                if (\count($all) >= 50 || array_sum(array_map(static fn (LocationEvidence $e): int => \count($e->points), $all)) + \count($batch->points) > 20000) {
                    throw new UnprocessableEntityHttpException('Limite de preuves par séance atteinte.');
                }
                $this->em->persist($batch);
                $this->em->flush();
            }
            if (null !== $workout) {
                $this->match($workout);
            }

            return $workout;
        });
    }

    public function imported(Uuid $id): void
    {
        $this->em->wrapInTransaction(function () use ($id): void {
            $this->lock();
            $workout = $this->em->find(Workout::class, $id);
            if (null !== $workout) {
                $this->match($workout);
            }
        });
    }

    public function purge(): int
    {
        $count = $this->em->createQuery('DELETE FROM '.LocationEvidence::class.' e WHERE e.endedAt <= :cutoff')->setParameter('cutoff', $this->clock->now()->modify('-30 days'))->execute();
        \assert(\is_int($count));

        return $count;
    }

    private function lock(): void
    {
        // shortcut: verrou global du pilote ; passer à des cohortes temporelles si la charge le justifie.
        // Verrou pris après COMMIT de l'import, jamais sous son verrou de progression.
        $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(329)');
    }

    private function match(Workout $anchor): void
    {
        if (null === $anchor->normalizedSport() || $anchor->endedAt() <= $this->clock->now()->modify('-30 days')) {
            return;
        }
        $builder = $this->em->createQueryBuilder()->select('w')->from(Workout::class, 'w')
            ->where('w.normalizedSport = :sport AND w.startedAt < :end AND w.endedAt > :start AND w.endedAt > :cutoff')
            ->setParameter('sport', $anchor->normalizedSport())->setParameter('start', $anchor->startedAt())->setParameter('end', $anchor->endedAt())->setParameter('cutoff', $this->clock->now()->modify('-30 days'))->orderBy('w.userId', 'ASC');
        /** @var list<Workout> $workouts */
        $workouts = $builder->getQuery()->getResult();
        $credited = $this->credited->among(array_map(static fn (Workout $w): string => $w->id()->toRfc4122(), $workouts));
        $workouts = array_values(array_filter($workouts, static fn (Workout $w): bool => isset($credited[$w->id()->toRfc4122()])));
        if (!isset($credited[$anchor->id()->toRfc4122()])) {
            $anchor->markTogether('UNAVAILABLE', 1, $this->rulesets->version());

            return;
        }
        $tracks = [];
        $origins = [];
        foreach ($workouts as $workout) {
            $points = [];
            foreach ($this->evidenceFor($workout->userId(), $workout->source()->value, $workout->externalId() ?? '') as $evidence) {
                if ($evidence->startedAt != $workout->startedAt() || $evidence->endedAt != $workout->endedAt()) {
                    continue;
                }
                $origins[$workout->id()->toRfc4122()][$evidence->origin] = true;
                foreach ($evidence->points as $point) {
                    if (!isset($points[$point['at']]) || [$point['accuracy'], $point['latitude'], $point['longitude']] < [$points[$point['at']]['accuracy'], $points[$point['at']]['latitude'], $points[$point['at']]['longitude']]) {
                        $points[$point['at']] = $point;
                    }
                }
            }
            ksort($points);
            $tracks[$workout->id()->toRfc4122()] = new TogetherTrack($workout->id()->toRfc4122(), $workout->userId()->toRfc4122(), $workout->normalizedSport() ?? '', $workout->startedAt()->getTimestamp(), $workout->endedAt()->getTimestamp(), array_values($points));
        }
        /** @var array<string, mixed> $settings */
        $settings = $this->rulesets->snapshot()['together'];
        $detector = new TogetherDetector(new TogetherRules($settings));
        foreach ($workouts as $workout) {
            $track = $tracks[$workout->id()->toRfc4122()];
            $group = $detector->groupFor($track, array_values($tracks));
            $count = \count($group);
            if ($count > $workout->together()['multiplier']) {
                $this->rewards->grant($workout->id(), $count, $this->rulesets->version());
            }
            $proof = $count > 1 ? ['workoutIds' => array_map(static fn (TogetherTrack $t): string => $t->id, $group), 'provenance' => array_intersect_key($origins, array_flip(array_map(static fn (TogetherTrack $t): string => $t->id, $group))), 'metrics' => $detector->proof($group), 'thresholds' => $settings] : [];
            $workout->markTogether($count > 1 ? 'VERIFIED' : 'INSUFFICIENT_EVIDENCE', $count, $this->rulesets->version(), $proof);
        }
        $this->em->flush();
    }

    /** @return list<LocationEvidence> */
    private function evidenceFor(Uuid $user, string $source, string $externalId): array
    {
        return $this->em->getRepository(LocationEvidence::class)->findBy(['userId' => $user, 'source' => $source, 'externalId' => $externalId]);
    }
}
