<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use App\Shared\Domain\Activity\AttributeGains;
use Symfony\Component\Uid\Uuid;

/**
 * Ce qui sépare un snapshot de ce que le ledger dit qu'il devrait être.
 *
 * **La comparaison porte sur toutes les colonnes, pas seulement sur le total.** Un bug de
 * projection peut très bien laisser `total_xp` juste et `earned_skill_points` faux — c'est
 * même le cas le plus vicieux, parce que le joueur voit le bon nombre d'XP et le mauvais
 * nombre de points à dépenser. Ne comparer que le total ferait passer ce bug-là pour une
 * base saine. Les quatre caractéristiques (#160) rejoignent `$expected`/`$actual` par
 * {@see AttributeGains::toArray()} plutôt que d'être récrites champ à champ ici : une
 * cinquième caractéristique qui rejoindrait ce type un jour rejoindrait la comparaison
 * sans toucher ce fichier.
 *
 * Fonction pure : deux entrées, une sortie, aucune base. C'est ce qui permet de la tester
 * par table de cas plutôt qu'en abîmant une ligne pour voir.
 */
final readonly class SnapshotDivergence
{
    /**
     * @param array<string, array{stored: int|null, expected: int|null}> $fields les seules colonnes qui diffèrent
     */
    private function __construct(
        public Uuid $userId,
        public array $fields,
    ) {
    }

    /**
     * L'écart entre ce qui est stocké et ce que la courbe projette du ledger, ou `null` si
     * les deux disent la même chose.
     *
     * Un snapshot **absent** face à un ledger non vide est un écart comme un autre : la
     * ligne aurait dû être posée par le premier crédit, et son absence se lit comme des
     * colonnes à `null`. L'absence face à un ledger vide, en revanche, n'est pas un défaut
     * — c'est l'état normal d'un compte qui vient de s'inscrire.
     *
     * @param ProgressionSnapshot|null $stored           la ligne telle qu'elle est en base
     * @param int                      $ledgerTotal      la somme du ledger, qui fait autorité
     * @param AttributeGains           $ledgerAttributes la répartition du même ledger, par caractéristique (#160)
     */
    public static function between(
        Uuid $userId,
        ?ProgressionSnapshot $stored,
        int $ledgerTotal,
        AttributeGains $ledgerAttributes,
        LevelCurve $curve,
    ): ?self {
        // L'état normal d'un compte qui vient de s'inscrire : c'est le premier crédit qui
        // pose la ligne, sous verrou. Le signaler ferait crier la sonde à chaque
        // inscription, et le « réparer » écrirait une ligne de zéros que
        // `GET /api/progression` sait déjà servir sans.
        if (null === $stored && 0 === $ledgerTotal) {
            return null;
        }

        $standing = $curve->standingAt($ledgerTotal);

        $expected = [
            'totalXp' => $ledgerTotal,
            'level' => $standing->level,
            'xpIntoLevel' => $standing->xpIntoLevel,
            'xpToNextLevel' => $standing->xpToNextLevel,
            'earnedSkillPoints' => $standing->earnedSkillPoints,
            ...$ledgerAttributes->toArray(),
        ];

        $actual = null === $stored ? array_fill_keys(array_keys($expected), null) : [
            'totalXp' => $stored->totalXp(),
            'level' => $stored->level(),
            'xpIntoLevel' => $stored->xpIntoLevel(),
            'xpToNextLevel' => $stored->xpToNextLevel(),
            'earnedSkillPoints' => $stored->earnedSkillPoints(),
            ...$stored->attributes()->toArray(),
        ];

        $fields = [];

        foreach ($expected as $name => $value) {
            if ($actual[$name] !== $value) {
                $fields[$name] = ['stored' => $actual[$name], 'expected' => $value];
            }
        }

        return [] === $fields ? null : new self($userId, $fields);
    }
}
