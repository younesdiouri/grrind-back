<?php

declare(strict_types=1);

namespace App\Progression\Domain;

use InvalidArgumentException;

/**
 * Les rendements décroissants sur le cumul de la journée.
 *
 * **Par tranche, pas par palier global.** Une séance à cheval sur deux tranches est
 * coupée : ses premières minutes valent le poids de la tranche où elles tombent, les
 * suivantes celui de la tranche d'après. Un palier global — « au-delà d'une heure, tout
 * vaut 60 % » — produirait une falaise où la 61ᵉ minute ferait perdre de l'XP déjà acquise,
 * et le joueur apprendrait à s'arrêter à 59.
 *
 * Le poids porte sur le **temps**, pas sur l'XP : la fonction rend un nombre de secondes
 * retenues, dont le barème tire ensuite un socle. C'est équivalent (le socle est linéaire)
 * et bien plus simple à garder en arithmétique entière, puisqu'il n'y a qu'une troncature
 * au lieu d'une par tranche.
 */
final readonly class DiminishingReturns
{
    /** @var list<array{seconds: int, weight: int}> bornes hautes du cumul, en secondes */
    private array $brackets;

    /**
     * @param list<array{up_to_minutes: int, weight_percent: int}> $brackets
     * @param int                                                  $beyondWeightPercent ce que vaut une minute au-delà de la dernière tranche
     */
    public function __construct(array $brackets, private int $beyondWeightPercent)
    {
        if ([] === $brackets) {
            throw new InvalidArgumentException('Il faut au moins une tranche de rendement.');
        }

        self::assertIsAWeight($beyondWeightPercent, 'Le poids au-delà de la dernière tranche');

        $normalized = [];
        $previousMinutes = 0;
        $previousWeight = 100;

        foreach ($brackets as $bracket) {
            if ($bracket['up_to_minutes'] <= $previousMinutes) {
                throw new InvalidArgumentException(\sprintf('Les tranches doivent être strictement croissantes : %d arrive après %d.', $bracket['up_to_minutes'], $previousMinutes));
            }

            self::assertIsAWeight($bracket['weight_percent'], \sprintf('Le poids de la tranche jusqu\'à %d min', $bracket['up_to_minutes']));

            // « Décroissants » est le nom de la mécanique, pas une indication : un poids
            // qui remonterait rendrait rentable d'attendre avant de reprendre.
            if ($bracket['weight_percent'] > $previousWeight) {
                throw new InvalidArgumentException(\sprintf('Un rendement ne remonte pas : %d %% après %d %%.', $bracket['weight_percent'], $previousWeight));
            }

            $normalized[] = ['seconds' => $bracket['up_to_minutes'] * 60, 'weight' => $bracket['weight_percent']];
            $previousMinutes = $bracket['up_to_minutes'];
            $previousWeight = $bracket['weight_percent'];
        }

        if ($beyondWeightPercent > $previousWeight) {
            throw new InvalidArgumentException(\sprintf('Un rendement ne remonte pas : %d %% au-delà de la dernière tranche, après %d %%.', $beyondWeightPercent, $previousWeight));
        }

        $this->brackets = $normalized;
    }

    /**
     * Les secondes retenues d'une séance, sachant ce que le joueur a déjà accumulé
     * aujourd'hui. C'est `$alreadyToday` qui place la séance sur la courbe : la même durée
     * ne vaut pas la même chose selon l'heure à laquelle on arrive.
     *
     * @throws InvalidArgumentException
     */
    public function retain(int $alreadyTodaySeconds, int $sessionSeconds): int
    {
        if ($alreadyTodaySeconds < 0 || $sessionSeconds < 0) {
            throw new InvalidArgumentException('Un cumul de la journée ne peut pas être négatif.');
        }

        $retained = 0;
        $cursor = $alreadyTodaySeconds;
        $remaining = $sessionSeconds;

        foreach ($this->brackets as $bracket) {
            if (0 === $remaining) {
                return $retained;
            }

            // Tranche déjà dépassée avant même que la séance commence.
            if ($cursor >= $bracket['seconds']) {
                continue;
            }

            $slice = min($remaining, $bracket['seconds'] - $cursor);
            $retained += intdiv($slice * $bracket['weight'], 100);
            $cursor += $slice;
            $remaining -= $slice;
        }

        return $retained + intdiv($remaining * $this->beyondWeightPercent, 100);
    }

    private static function assertIsAWeight(int $percent, string $what): void
    {
        // Au-dessus de 100, ce ne serait plus un rendement décroissant mais une prime au
        // volume — exactement l'inverse de ce que ce garde-fou existe pour faire.
        if ($percent < 0 || $percent > 100) {
            throw new InvalidArgumentException(\sprintf('%s doit être entre 0 et 100 %%, pas %d.', $what, $percent));
        }
    }
}
