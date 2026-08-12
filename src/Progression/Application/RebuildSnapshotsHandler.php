<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\SnapshotDivergence;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Compare chaque snapshot à ce que le ledger dit, et — si on le lui demande — le réécrit.
 *
 * **La comparaison passe avant l'écriture, y compris hors `--dry-run`.** Réécrire tout le
 * monde « pour être sûr » coûterait une réécriture complète de la table à chaque passe, et
 * surtout ça remonterait `updated_at` sur des comptes qui n'ont rien fait — or cette colonne
 * sort dans `GET /api/progression` sous le nom `lastProgressionAt`. Une commande de
 * maintenance qui fait mentir l'écran d'un joueur est pire que le problème qu'elle répare.
 *
 * **Une transaction par compte, pas une pour toute la passe.** Le verrou est le même que
 * celui de la complétion de séance : il porte sur une ligne, il se prend et se rend en
 * quelques microsecondes. Une transaction unique sur toute la base verrouillerait chaque
 * joueur pendant toute la durée de la passe, et une reconstruction deviendrait une
 * interruption de service.
 *
 * **La réparation ne fait pas confiance à la comparaison.** Sous le verrou, le total est
 * relu et la projection refaite : si la comparaison avait vu passer une complétion
 * concurrente, la réécriture reste juste — au pire elle ne change rien.
 */
final readonly class RebuildSnapshotsHandler
{
    public function __construct(
        private ProgressionSnapshotRepository $snapshots,
        private XpTransactionRepository $ledger,
        private LevelCurve $curve,
        private ClockInterface $clock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(RebuildSnapshots $command): RebuildReport
    {
        $checked = 0;
        $diverged = 0;
        $repaired = 0;
        $samples = [];

        foreach ($this->playersOf($command) as $userId) {
            ++$checked;

            $divergence = SnapshotDivergence::between(
                $userId,
                $this->snapshots->ofPlayer($userId),
                $this->ledger->totalOf($userId),
                $this->curve,
            );

            if (null !== $divergence) {
                ++$diverged;

                if (\count($samples) < RebuildReport::SAMPLE_SIZE) {
                    $samples[] = $divergence;
                }

                if (!$command->dryRun) {
                    $this->repair($userId);
                    ++$repaired;
                }
            }

            // Une passe sur toute la base charge autant d'entités qu'il y a de comptes. Les
            // oublier à chaque tour garde la mémoire plate — c'est la différence entre une
            // commande qui tient sur un million de joueurs et une qui meurt à cent mille.
            $this->entityManager->clear();
        }

        return new RebuildReport($checked, $diverged, $repaired, $samples);
    }

    /**
     * Réécrit la ligne d'un compte, par le chemin exact de la complétion de séance :
     * verrou, relecture du ledger, reprojection.
     *
     * Emprunter ce chemin plutôt qu'écrire les colonnes à la main est ce qui donne son sens
     * à la commande. Une reconstruction qui projetterait autrement que la production ne
     * prouverait rien : elle rendrait les deux d'accord sur une troisième valeur.
     */
    private function repair(Uuid $userId): void
    {
        $this->entityManager->wrapInTransaction(function () use ($userId): void {
            $snapshot = $this->snapshots->lockFor($userId, $this->curve);
            $snapshot->retotal($this->ledger->totalOf($userId), $this->curve, $this->clock->now());
            $this->snapshots->commit();
        });
    }

    /**
     * @return iterable<Uuid>
     */
    private function playersOf(RebuildSnapshots $command): iterable
    {
        return null === $command->userId
            ? $this->snapshots->everyKnownPlayer()
            : [$command->userId];
    }
}
