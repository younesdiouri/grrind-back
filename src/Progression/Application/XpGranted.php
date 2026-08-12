<?php

declare(strict_types=1);

namespace App\Progression\Application;

use App\Progression\Domain\LevelStanding;
use App\Progression\Domain\ProgressionSnapshot;
use App\Progression\Domain\Title;
use App\Progression\Domain\XpAward;

/**
 * Ce qui s'est passé quand l'XP a été accordée : ce que la séance a rapporté, d'où le joueur
 * partait, où il en est ensuite, et ce qu'il a franchi au passage.
 *
 * C'est la matière première du `RewardSummary` (#22), pensé pour être animé séquentiellement
 * par le client — d'où les niveaux **listés** et non un simple booléen : un joueur qui en gagne
 * trois d'un coup doit les voir défiler tous les trois.
 */
final readonly class XpGranted
{
    /**
     * @param LevelStanding $standingBefore le palier de départ **entier**, pas seulement son
     *                                      niveau : sans sa largeur, une barre d'XP ne peut pas
     *                                      être placée avant d'être remplie (#79)
     * @param list<int>     $levelsReached  vide si aucun niveau n'a été franchi
     * @param list<Title>   $titlesUnlocked vide le plus souvent : un titre est un événement rare, c'est ce qui en fait un
     */
    public function __construct(
        public XpAward $award,
        public ProgressionSnapshot $snapshot,
        public LevelStanding $standingBefore,
        public array $levelsReached,
        /** Les points de compétence que ces niveaux ont accordés. */
        public int $skillPointsGranted,
        public array $titlesUnlocked = [],
    ) {
    }
}
