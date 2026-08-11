<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\XpCalculator;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Écrit au ledger et reprojette le snapshot, **dans une seule transaction**.
 *
 * C'est le cœur de ce qui deviendra la transaction de complétion (#21) : celle-ci
 * l'entourera du loot, du streak et de l'outbox, mais la séquence écrite ici ne bougera
 * pas. Elle tient en quatre gestes, dans cet ordre et pas un autre :
 *
 * 1. **verrouiller la ligne de progression du joueur.** Sans ça, deux complétions
 *    simultanées lisent le même total, calculent le même niveau et s'écrasent l'une
 *    l'autre. Le verrou porte sur une ligne : deux joueurs ne s'attendent jamais ;
 * 2. **lire la charge du jour** — après le verrou, donc en voyant ce que la transaction
 *    concurrente a déjà écrit, sans quoi les rendements décroissants se contourneraient
 *    en clôturant deux séances à la même seconde ;
 * 3. **calculer**, purement, et écrire l'XpTransaction ;
 * 4. **reprojeter** le snapshot sur le nouveau total.
 *
 * Le total reprojeté est **relu au ledger** plutôt qu'additionné au snapshot : c'est ce
 * qui garde le snapshot réellement dérivé, et ce qui fait qu'une divergence se corrige
 * toute seule à la complétion suivante au lieu de s'accumuler.
 */
final readonly class GrantXpHandler
{
    public function __construct(
        private XpTransactionRepository $ledger,
        private ProgressionSnapshotRepository $snapshots,
        private DailyLoadProvider $dailyLoad,
        private XpCalculator $calculator,
        private LevelCurve $curve,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(GrantXp $command): XpGranted
    {
        $now = $this->clock->now();

        return $this->entityManager->wrapInTransaction(function () use ($command, $now): XpGranted {
            $snapshot = $this->snapshots->lockFor($command->userId, $this->curve);

            $award = $this->calculator->calculate(
                $command->discipline,
                $command->durationSeconds,
                $command->modifiers,
                $this->dailyLoad->of($command->userId, $command->discipline, $now),
            );

            $this->ledger->add(XpTransaction::creditFor(
                $command->userId,
                $command->sessionId,
                $command->discipline,
                $command->durationSeconds,
                $award,
                $now,
            ));
            $this->ledger->commit();

            $before = $snapshot->earnedSkillPoints();
            $levelsReached = $snapshot->retotal($this->ledger->totalOf($command->userId), $this->curve, $now);
            $this->snapshots->commit();

            return new XpGranted($award, $snapshot, $levelsReached, $snapshot->earnedSkillPoints() - $before);
        });
    }
}
