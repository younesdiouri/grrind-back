<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\Discipline;

/**
 * Le relevé d'un joueur, tel que les conditions de titre le lisent : son niveau, son XP, et
 * ce qu'il a fait par discipline.
 *
 * **Entièrement dérivé du ledger et de la courbe**, comme `LevelStanding` : deux joueurs au
 * même relevé débloquent les mêmes titres, et rejouer le calcul donne toujours le même
 * résultat. C'est ce qui rend l'évaluation testable par table de cas, sans base.
 *
 * Les compteurs sont **nets et signés**. Une séance invalidée écrit au ledger une durée et
 * un compte négatifs, donc elle disparaît d'elle-même du relevé — exactement comme elle
 * disparaît de l'XP. Ce qu'elle ne défait pas, c'est un titre déjà débloqué : le relevé peut
 * repasser sous un seuil, la ligne dans `player_title`, elle, reste.
 */
final readonly class PlayerRecord
{
    /**
     * @param array<string, int> $sessionsByDiscipline valeur de discipline → séances nettes
     * @param array<string, int> $secondsByDiscipline  valeur de discipline → secondes nettes
     */
    public function __construct(
        public int $level,
        public int $totalXp,
        private array $sessionsByDiscipline = [],
        private array $secondsByDiscipline = [],
    ) {
    }

    /** Le joueur qui n'a encore rien fait. Sert de relevé à qui n'a pas de ligne au ledger. */
    public static function untouched(int $level = 1): self
    {
        return new self($level, 0);
    }

    /**
     * Les séances d'une discipline, ou toutes si aucune n'est nommée.
     *
     * Le total se somme plutôt que d'être stocké à part : une seule vérité pour deux
     * lectures, et un cumul qui ne peut pas diverger de son détail.
     */
    public function sessionsIn(?Discipline $discipline): int
    {
        if (null === $discipline) {
            return array_sum($this->sessionsByDiscipline);
        }

        return $this->sessionsByDiscipline[$discipline->value] ?? 0;
    }

    public function secondsIn(Discipline $discipline): int
    {
        return $this->secondsByDiscipline[$discipline->value] ?? 0;
    }
}
