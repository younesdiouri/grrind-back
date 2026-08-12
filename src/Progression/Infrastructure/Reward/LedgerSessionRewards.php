<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure\Reward;

use App\Progression\Application\GrantXp;
use App\Progression\Application\GrantXpHandler;
use App\Progression\Domain\Title;
use App\Progression\Domain\TitleProgress;
use App\Progression\Domain\XpBreakdownLine;
use App\Progression\Infrastructure\Translation\TitleTranslator;
use App\Shared\Application\PlayerTitle;
use App\Shared\Application\SessionReward;
use App\Shared\Application\SessionRewards;
use App\Shared\Application\XpLine;
use App\Shared\Domain\Event\TrainingSessionCompleted;

/**
 * L'implémentation du port {@see SessionRewards} : c'est par cette classe, et uniquement
 * par elle, que `Training` crédite un joueur.
 *
 * Elle ne décide de rien. Toute la séquence — verrou, charge du jour, modificateurs,
 * calcul, ledger, snapshot, titres — est celle de {@see GrantXpHandler}, écrite au Lot 3 et
 * inchangée ici. Cette classe traduit : ce qui entre est un fait de `Training`, ce qui sort
 * est du vocabulaire de `Shared`.
 *
 * **Le verrou est posé par le handler, pas ici**, et il l'est *à l'intérieur* de la
 * transaction ouverte par `Training`. Le `wrapInTransaction` imbriqué ne rouvre rien : DBAL
 * en fait un point de sauvegarde, le verrou de ligne court jusqu'au COMMIT extérieur, et
 * une exception levée après le crédit défait bien le crédit.
 */
final readonly class LedgerSessionRewards implements SessionRewards
{
    public function __construct(
        private GrantXpHandler $grantXp,
        private TitleTranslator $titles,
    ) {
    }

    public function creditFor(TrainingSessionCompleted $completion): SessionReward
    {
        $granted = ($this->grantXp)(new GrantXp(
            $completion->userId,
            $completion->sessionId,
            $completion->discipline,
            $completion->durationSeconds,
        ));

        $snapshot = $granted->snapshot;
        $before = $granted->standingBefore;

        return new SessionReward(
            $granted->award->amount(),
            array_map(
                static fn (XpBreakdownLine $line): XpLine => new XpLine($line->source->value, $line->amount),
                $granted->award->breakdown->lines,
            ),
            // Le palier d'où le joueur part, dans son entier : le client y place la barre
            // avant de la remplir, et sa largeur ne se redéduit pas du reste du payload
            // quand plusieurs niveaux sont franchis (#79). Le lire sur le snapshot serait
            // le lire *après* la reprojection, donc lire l'arrivée — d'où `standingBefore`,
            // capturé par le handler du bon côté du `retotal`.
            $before->level,
            $before->xpIntoLevel,
            $before->xpToNextLevel,
            $snapshot->level(),
            $snapshot->totalXp(),
            $snapshot->xpIntoLevel(),
            $snapshot->xpToNextLevel(),
            $granted->levelsReached,
            $granted->skillPointsGranted,
            array_map(
                // Barre pleine et date de la transaction : un titre qui vient d'être
                // débloqué l'est à l'instant même que `TitleUnlocker` a écrit en base —
                // celui que le snapshot porte, pas une seconde lecture de l'horloge.
                fn (Title $title): PlayerTitle => $this->titles->describe(
                    TitleProgress::completed($title),
                    $snapshot->updatedAt(),
                ),
                $granted->titlesUnlocked,
            ),
            $granted->award->rulesetVersion,
        );
    }
}
