<?php

declare(strict_types=1);

namespace App\Combat\Domain;

use OverflowException;

/**
 * Les fractions continues comparent des produits sans les former : sqrt(a*b) reste
 * exacte même quand a*b dépasse PHP_INT_MAX. Aucun flottant n'arbitre le combat.
 * Un résultat non représentable est refusé explicitement, jamais converti en float.
 */
final class CombatMath
{
    public static function compare(int $a, int $b, int $c, int $d): int
    {
        $sign = 1;
        while (true) {
            $comparison = intdiv($a, $b) <=> intdiv($c, $d);
            if (0 !== $comparison) {
                return $sign * $comparison;
            }
            $a %= $b;
            $c %= $d;
            if (0 === $a || 0 === $c) {
                return $sign * ($a <=> $c);
            }
            [$a, $b, $c, $d] = [$b, $a, $d, $c];
            $sign = -$sign;
        }
    }

    public static function pair(int $a, int $b): int
    {
        if ($a <= 0 || $b <= 0) {
            return 0;
        }
        $low = min($a, $b);
        $high = max($a, $b);
        while ($low < $high) {
            $mid = $low + intdiv($high - $low, 2) + 1;
            if (self::compare($mid, $a, $b, $mid) <= 0) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    public static function rate(int $score, int $cap, int $threshold): int
    {
        $low = 0;
        $high = $cap;
        while ($low < $high) {
            $mid = $low + intdiv($high - $low, 2) + 1;
            if ($mid < $cap && self::compare($mid, $cap - $mid, $score, $threshold) <= 0) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    /** Coefficient et diviseur bornés à 10^6 par les règles ; division avant produit. */
    public static function scale(int $value, int $coefficient, int $divisor = 1000): int
    {
        $whole = intdiv($value, $divisor);
        $remainder = intdiv(($value % $divisor) * $coefficient, $divisor);
        if (0 !== $coefficient && $whole > intdiv(\PHP_INT_MAX - $remainder, $coefficient)) {
            throw new OverflowException('Valeur de combat non représentable en entier.');
        }

        return $whole * $coefficient + $remainder;
    }

    public static function add(int $a, int $b): int
    {
        if (($b > 0 && $a > \PHP_INT_MAX - $b) || ($b < 0 && $a < \PHP_INT_MIN - $b)) {
            throw new OverflowException('Somme de combat non représentable en entier.');
        }

        return $a + $b;
    }
}
