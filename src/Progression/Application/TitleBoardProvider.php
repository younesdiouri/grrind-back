<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\TitleCatalog;
use App\Progression\Infrastructure\Doctrine\ActiveTitleRepository;
use App\Progression\Infrastructure\Doctrine\UnlockedTitleRepository;
use App\Progression\Infrastructure\Doctrine\XpTransactionRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Assemble le mur des titres : relève le joueur au ledger, situe le catalogue dessus, y
 * pose ce qui est déjà acquis.
 *
 * Toute l'impureté tient ici — trois lectures, aucune décision. Le classement, le calcul de
 * progression et le choix du prochain titre restent dans `TitleCatalog`, où ils se testent
 * sans base. Même partage qu'entre `DailyLoadProvider` et `XpCalculator`.
 */
final readonly class TitleBoardProvider
{
    public function __construct(
        private XpTransactionRepository $ledger,
        private UnlockedTitleRepository $unlockedTitles,
        private ActiveTitleRepository $activeTitles,
        private TitleCatalog $catalog,
        private LevelCurve $curve,
    ) {
    }

    public function of(Uuid $userId): TitleBoard
    {
        $record = $this->ledger->recordOf($userId, $this->curve);
        $unlockedAt = $this->unlockedTitles->unlockedBy($userId);

        return new TitleBoard(
            $this->catalog->progressOf($record, array_keys($unlockedAt)),
            $unlockedAt,
            $this->activeTitles->titleIdOf($userId),
            // Ce que le joueur a déjà est écarté d'après la **table**, pas d'après le relevé :
            // une séance invalidée peut ramener un compteur sous son seuil, et un titre déjà
            // acquis ne doit pas redevenir « le prochain à viser ».
            $this->catalog->nextFor($record, array_keys($unlockedAt)),
        );
    }
}
