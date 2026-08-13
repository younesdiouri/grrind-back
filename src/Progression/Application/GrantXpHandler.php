<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\XpCalculator;
use App\Progression\Domain\XpTransaction;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use App\Shared\Application\ModifierResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Écrit au ledger et reprojette le snapshot, **dans une seule transaction**.
 *
 * C'est le cœur de la transaction de complétion (#21), qui l'appelle par le port
 * `SessionRewards` et l'entoure du loot, du streak et de l'outbox. Le `wrapInTransaction`
 * ci-dessous devient alors un point de sauvegarde dans celle de `Training` : le verrou
 * court jusqu'au COMMIT extérieur, et un échec en aval défait bien le crédit. La séquence,
 * elle, ne bouge pas. Elle tient en six gestes, dans cet ordre et pas un autre :
 *
 * 1. **verrouiller la ligne de progression du joueur.** Sans ça, deux complétions
 *    simultanées lisent le même total, calculent le même niveau et s'écrasent l'une
 *    l'autre. Le verrou porte sur une ligne : deux joueurs ne s'attendent jamais ;
 * 2. **lire la charge du jour** — celle de la journée du *sport*, et après le verrou, donc
 *    en voyant ce que la transaction concurrente a déjà écrit ; sans quoi les rendements
 *    décroissants se contourneraient en créditant deux workouts à la même seconde. C'est
 *    aussi ce qui rend un import correct workout par workout : la charge est **relue à
 *    chaque appel**, donc le deuxième workout d'une même journée voit le premier, même
 *    lorsque les deux arrivent dans le même lot ;
 * 3. **résoudre les modificateurs actifs**, après le verrou pour la même raison : le
 *    streak et les objets équipés changent à l'intérieur de cette transaction-là, et un
 *    ensemble lu avant elle créditerait des bonus déjà périmés ;
 * 4. **calculer**, purement, et écrire l'XpTransaction ;
 * 5. **reprojeter** le snapshot sur le nouveau total ;
 * 6. **évaluer les titres**, une fois l'écriture faite, pour que la séance qui vient d'être
 *    créditée compte dans la condition qu'elle satisfait.
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
        private ModifierResolver $modifiers,
        private XpCalculator $calculator,
        private TitleUnlocker $titles,
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
                $this->modifiers->of($command->userId),
                // La journée du **sport**, pas celle de l'appel. Sur un import, les deux
                // sont à des jours d'écart, et c'est bien la charge de ce jour-là qui place
                // le workout sur la courbe des rendements décroissants.
                $this->dailyLoad->of($command->userId, $command->discipline, $command->occurredAt),
                $command->distanceMeters,
                $command->elevationGainMeters,
            );

            $this->ledger->add(XpTransaction::creditFor(
                $command->userId,
                $command->sessionId,
                $command->discipline,
                $command->durationSeconds,
                $award,
                $command->occurredAt,
            ));
            $this->ledger->commit();

            // Lu avant la reprojection, donc c'est bien le palier d'où le joueur part —
            // et il est cohérent avec `levelsReached` par construction, puisque `retotal`
            // compare au même état. Le client anime la barre depuis là (#79).
            $standingBefore = $snapshot->standing();
            $levelsReached = $snapshot->retotal($this->ledger->totalOf($command->userId), $this->curve, $now);
            $this->snapshots->commit();

            return new XpGranted(
                $award,
                $snapshot,
                $standingBefore,
                $levelsReached,
                $snapshot->earnedSkillPoints() - $standingBefore->earnedSkillPoints,
                // En dernier, et dans la transaction : les conditions se lisent au ledger,
                // donc la séance qui vient d'être écrite compte pour le titre qu'elle
                // débloque. Le verrou posé plus haut sérialise l'évaluation avec elle —
                // deux complétions simultanées ne peuvent pas décider chacune de leur côté
                // qu'un titre est neuf.
                $this->titles->unlock($command->userId, $now),
            );
        });
    }
}
