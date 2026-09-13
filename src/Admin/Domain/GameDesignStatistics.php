<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use InvalidArgumentException;

/**
 * Wilson bilatéral à 95 %, y compris 0/n et n/n. Les quantiles sont au rang supérieur.
 * L'incertitude décrit le hasard des combats conditionnellement au panel choisi ;
 * ce panel exploratoire n'est pas un échantillon représentatif des joueurs.
 *
 * @phpstan-type Observation array{win: bool, limit: bool, ticks: int, playerHpPercent: float, enemyHpPercent: float}
 * @phpstan-type Summary array{n: int, wins: int, winRate: float, low: float, high: float, limitRate: float, meanTicks: float, medianTicks: int, p90Ticks: int, meanPlayerHpPercent: float, meanEnemyHpPercent: float, hpHistogram: list<int>}
 */
final class GameDesignStatistics
{
    /** @return array{low: float, high: float} */
    public static function wilson(int $wins, int $n): array
    {
        if ($n < 1 || $wins < 0 || $wins > $n) {
            throw new InvalidArgumentException('Effectif et victoires incohérents.');
        }
        $z = 1.959963984540054;
        $p = $wins / $n;
        $denominator = 1 + $z * $z / $n;
        $center = ($p + $z * $z / (2 * $n)) / $denominator;
        $radius = $z * sqrt($p * (1 - $p) / $n + $z * $z / (4 * $n * $n)) / $denominator;

        return ['low' => 100 * max(0.0, $center - $radius), 'high' => 100 * min(1.0, $center + $radius)];
    }

    /** @param non-empty-list<Observation> $observations
     * @return Summary
     */
    public static function summarize(array $observations): array
    {
        $n = \count($observations);
        $wins = $limits = $ticksTotal = 0;
        $playerHp = $enemyHp = 0.0;
        $ticks = [];
        $histogram = array_fill(0, 10, 0);
        foreach ($observations as $observation) {
            $wins += $observation['win'] ? 1 : 0;
            $limits += $observation['limit'] ? 1 : 0;
            $ticksTotal += $observation['ticks'];
            $ticks[] = $observation['ticks'];
            $playerHp += $observation['playerHpPercent'];
            $enemyHp += $observation['enemyHpPercent'];
            ++$histogram[min(9, (int) floor($observation['playerHpPercent'] / 10))];
        }
        sort($ticks);

        return ['n' => $n, 'wins' => $wins, 'winRate' => 100.0 * $wins / $n, ...self::wilson($wins, $n), 'limitRate' => 100.0 * $limits / $n, 'meanTicks' => (float) $ticksTotal / $n, 'medianTicks' => $ticks[(int) ceil($n * 0.5) - 1], 'p90Ticks' => $ticks[(int) ceil($n * 0.9) - 1], 'meanPlayerHpPercent' => $playerHp / $n, 'meanEnemyHpPercent' => $enemyHp / $n, 'hpHistogram' => array_values($histogram)];
    }
}
