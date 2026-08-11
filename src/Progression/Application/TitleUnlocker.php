<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\Title;
use App\Progression\Domain\TitleCatalog;
use App\Progression\Infrastructure\Doctrine\UnlockedTitleRepository;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

/**
 * Évalue le catalogue sur le relevé du joueur et enregistre ce qui vient de se débloquer.
 *
 * **Appelé à la complétion d'une séance, dans la transaction**, après l'écriture au ledger :
 * c'est cet ordre qui fait que la séance qui vient d'être créditée compte pour le titre
 * qu'elle débloque. Une évaluation avant l'écriture ferait attendre la séance suivante au
 * joueur qui vient d'atteindre son centième entraînement — le genre de décalage d'un cran
 * que personne ne comprend et que tout le monde remarque.
 *
 * Le relevé est **relu au ledger** plutôt que déduit de ce que la transaction vient
 * d'ajouter : même raison que pour le snapshot reprojeté sur la somme du ledger, une
 * divergence se corrige alors d'elle-même à la complétion suivante au lieu de s'accumuler.
 * Et un titre ajouté au catalogue est **rétroactif** sans reprise de données — le prochain
 * entraînement l'accorde à qui remplissait déjà sa condition.
 *
 * Rien ici ne retire quoi que ce soit : `newlyUnlockedBy()` ne rend que des ajouts, et le
 * dépôt ne sait pas supprimer.
 */
final readonly class TitleUnlocker
{
    public function __construct(
        private XpTransactionRepository $ledger,
        private UnlockedTitleRepository $unlockedTitles,
        private TitleCatalog $catalog,
        private LevelCurve $curve,
    ) {
    }

    /**
     * @return list<Title> ce qui vient d'être débloqué, dans l'ordre du catalogue ; vide le plus souvent
     */
    public function unlock(Uuid $userId, DateTimeImmutable $now): array
    {
        $newlyUnlocked = $this->catalog->newlyUnlockedBy(
            $this->ledger->recordOf($userId, $this->curve),
            array_keys($this->unlockedTitles->unlockedBy($userId)),
        );

        if ([] === $newlyUnlocked) {
            return [];
        }

        foreach ($newlyUnlocked as $title) {
            $this->unlockedTitles->record($userId, $title, $now);
        }

        $this->unlockedTitles->commit();

        return $newlyUnlocked;
    }
}
