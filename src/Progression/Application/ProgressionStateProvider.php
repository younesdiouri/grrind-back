<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\LevelStanding;
use App\Progression\Domain\ProgressionSnapshot;
use App\Progression\Domain\TitleCatalog;
use App\Progression\Domain\TitleProgress;
use App\Progression\Infrastructure\Doctrine\ActiveTitleRepository;
use App\Progression\Infrastructure\Doctrine\ProgressionSnapshotRepository;
use App\Progression\Infrastructure\Doctrine\UnlockedTitleRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Assemble l'état du joueur. Trois lectures, aucune décision — même partage qu'entre
 * {@see DailyLoadProvider} et `XpCalculator`.
 *
 * **Le snapshot est lu, pas recalculé.** Ses colonnes de niveau sont reprises telles
 * quelles au lieu d'être reprojetées de son total : reprojeter ici masquerait exactement
 * la divergence que la commande de reconstruction (#20) existe pour détecter, et ferait de
 * chaque ouverture d'app une réparation silencieuse.
 *
 * **Aucune écriture.** Un joueur qui n'a jamais rien fait n'a pas de ligne, et lire son
 * état n'a pas à en créer une : c'est le premier crédit qui la pose, sous verrou. Une
 * lecture qui écrit, c'est un `GET` qui n'est plus rejouable et un verrou pris pour rien.
 */
final readonly class ProgressionStateProvider
{
    public function __construct(
        private ProgressionSnapshotRepository $snapshots,
        private UnlockedTitleRepository $unlockedTitles,
        private ActiveTitleRepository $activeTitles,
        private TitleCatalog $catalog,
        private LevelCurve $curve,
        private string $rulesetVersion,
    ) {
    }

    public function of(Uuid $userId): ProgressionState
    {
        $snapshot = $this->snapshots->ofPlayer($userId);
        $standing = self::standingOf($snapshot, $this->curve);
        $unlockedAt = $this->unlockedTitles->unlockedBy($userId);

        return new ProgressionState(
            $snapshot?->totalXp() ?? 0,
            $standing,
            // Accordés moins dépensés — et rien ne se dépense avant les arbres de
            // compétences (#32). Le calcul vit ici plutôt que dans la réponse pour qu'il
            // n'y ait qu'un endroit à ouvrir au Lot 7.
            $standing->earnedSkillPoints,
            $this->acquiredBy($unlockedAt),
            $unlockedAt,
            $this->activeTitles->titleIdOf($userId),
            $this->rulesetVersion,
            $snapshot?->updatedAt(),
        );
    }

    /**
     * Les titres acquis, situés sans relever le joueur — voir
     * {@see TitleProgress::completed()}.
     *
     * Le parcours part du **catalogue** et non des lignes en base : c'est ce qui donne
     * l'ordre de déclaration, le même qu'à `GET /api/titles`, et ce qui écarte au passage
     * un titre retiré du YAML dont la ligne survit en base.
     *
     * @param array<string, DateTimeImmutable> $unlockedAt
     *
     * @return list<TitleProgress>
     */
    private function acquiredBy(array $unlockedAt): array
    {
        $acquired = [];

        foreach ($this->catalog->all() as $title) {
            if (isset($unlockedAt[$title->id])) {
                $acquired[] = TitleProgress::completed($title);
            }
        }

        return $acquired;
    }

    /**
     * Le joueur sans ligne de progression est au départ de la courbe, pas en erreur : il
     * vient de s'inscrire, et son écran doit s'afficher.
     */
    private static function standingOf(?ProgressionSnapshot $snapshot, LevelCurve $curve): LevelStanding
    {
        if (null === $snapshot) {
            return $curve->standingAt(0);
        }

        return new LevelStanding(
            $snapshot->level(),
            $snapshot->xpIntoLevel(),
            $snapshot->xpToNextLevel(),
            $snapshot->earnedSkillPoints(),
        );
    }
}
