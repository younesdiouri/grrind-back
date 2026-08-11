<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use InvalidArgumentException;

/**
 * La courbe de niveaux, chargée depuis `config/game/v1/levels.yaml`.
 *
 * **Le niveau est une projection du ledger, jamais une donnée.** Cette classe est la
 * fonction qui projette : un total d'XP entre, un `LevelStanding` sort. Rien d'autre dans
 * le code n'a le droit de décider qu'un joueur est niveau 12.
 *
 * Les seuils sont **cumulés** et non incrémentaux : c'est ce qui rend la projection
 * indépendante de l'historique. Un joueur à 3 060 XP est niveau 10, qu'il y soit arrivé en
 * un mois ou en un jour, et qu'on ait rejoué ou non les transactions qui l'y ont mené.
 */
final readonly class LevelCurve
{
    /** @var list<array{level: int, total_xp: int, skill_points: int}> par niveau croissant */
    private array $levels;

    /** @var list<int> points de compétence cumulés jusqu'à l'indice, inclus */
    private array $cumulativeSkillPoints;

    /**
     * @param list<array{level: int, total_xp: int, skill_points: int}> $levels
     */
    public function __construct(array $levels)
    {
        if ([] === $levels) {
            throw new InvalidArgumentException('Une courbe de niveaux sans niveau ne projette rien.');
        }

        if (1 !== $levels[0]['level'] || 0 !== $levels[0]['total_xp']) {
            throw new InvalidArgumentException('La courbe doit commencer au niveau 1, à 0 XP.');
        }

        $cumulative = [];
        $total = 0;

        foreach ($levels as $index => $level) {
            // Consécutifs et croissants : un trou dans la numérotation ferait sauter un
            // niveau à tout le monde, et une inversion rendrait la projection ambiguë.
            if ($level['level'] !== $index + 1) {
                throw new InvalidArgumentException(\sprintf('Les niveaux doivent se suivre sans trou : %d attendu, %d trouvé.', $index + 1, $level['level']));
            }

            if ($index > 0 && $level['total_xp'] <= $levels[$index - 1]['total_xp']) {
                throw new InvalidArgumentException(\sprintf('Le seuil du niveau %d (%d) n\'est pas au-dessus du précédent (%d).', $level['level'], $level['total_xp'], $levels[$index - 1]['total_xp']));
            }

            if ($level['skill_points'] < 0) {
                throw new InvalidArgumentException(\sprintf('Le niveau %d ne peut pas retirer de point de compétence.', $level['level']));
            }

            $total += $level['skill_points'];
            $cumulative[] = $total;
        }

        $this->levels = $levels;
        $this->cumulativeSkillPoints = $cumulative;
    }

    /**
     * Le niveau atteint par ce total, et tout ce qui s'en déduit.
     *
     * Un total négatif rend le niveau 1 plutôt que de lever : il ne peut venir que d'une
     * annulation qui reprend plus que ce que le joueur a jamais eu, et le faire échouer
     * bloquerait la correction qu'on est précisément en train d'écrire.
     */
    public function standingAt(int $totalXp): LevelStanding
    {
        $totalXp = max(0, $totalXp);
        $index = 0;

        // Parcours linéaire assumé : cinquante entrées, appelées une fois par complétion.
        // Une dichotomie ici serait plus rapide à écrire qu'à relire.
        foreach ($this->levels as $position => $level) {
            if ($totalXp < $level['total_xp']) {
                break;
            }

            $index = $position;
        }

        $threshold = $this->levels[$index]['total_xp'];
        $next = $this->levels[$index + 1] ?? null;

        return new LevelStanding(
            $this->levels[$index]['level'],
            $totalXp - $threshold,
            null === $next ? null : $next['total_xp'] - $totalXp,
            $this->cumulativeSkillPoints[$index],
        );
    }

    public function maxLevel(): int
    {
        return $this->levels[\count($this->levels) - 1]['level'];
    }
}
