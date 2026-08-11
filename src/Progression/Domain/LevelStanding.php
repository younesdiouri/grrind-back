<?php

declare(strict_types=1);

namespace App\Progression\Domain;

/**
 * Où en est un joueur sur la courbe, pour un total d'XP donné.
 *
 * Entièrement dérivé : deux joueurs au même total ont le même standing, et le recalculer
 * donne toujours le même résultat. C'est ce qui fait de `progression_snapshot` un cache et
 * non une seconde vérité — la commande de reconstruction (#20) n'a qu'à rejouer ce calcul.
 */
final readonly class LevelStanding
{
    public function __construct(
        public int $level,
        /** XP acquise depuis le seuil de ce niveau. C'est le numérateur de la barre. */
        public int $xpIntoLevel,
        /** XP restant à gagner pour le niveau suivant, ou `null` au niveau maximum. */
        public ?int $xpToNextLevel,
        /** Points de compétence accordés par tous les niveaux atteints, cumulés. */
        public int $earnedSkillPoints,
    ) {
    }

    public function isMaxed(): bool
    {
        return null === $this->xpToNextLevel;
    }
}
