<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use App\Shared\Application\StreakState;
use DateTimeImmutable;
use DateTimeZone;

/**
 * La régularité du vrai sport (§7.5 du game design, #286) : une fonction pure, des jours
 * sportifs et un jour courant vers un {@see StreakState}.
 *
 * **Fenêtre glissante stricte.** Une série commence au premier jour sportif et vit tant
 * que toute fenêtre de 7 jours consécutifs depuis ce jour compte au plus un jour manqué :
 * deux jours manqués à moins de 7 jours d'écart la cassent, au second. On repart alors de
 * zéro au jour sportif suivant. « 12 sur 14 », « 18 sur 21 » et « 24 sur 28 » ne sont pas
 * des règles de plus : ils découlent de celle-ci, ce sont les semaines 2, 3 et 4. La
 * lecture « cumul par paliers », plus laxiste, a été écartée avec l'auteur.
 *
 * **Le jour courant n'est pas encore manqué.** La journée n'est pas finie : sans séance
 * pour l'instant, elle ne casse rien.
 *
 * **Les semaines sont ancrées sur le premier jour de la série**, jamais sur le calendrier :
 * la semaine *k* couvre `[A + 7(k−1), A + 7k − 1]` et rapporte son coffre dès qu'elle
 * compte 6 jours sportifs.
 *
 * Les seuils (6 sur 7, quatre paliers) sont la spec elle-même, pas un réglage : seule la
 * durée qui fait un jour sportif est administrable, dans le réglage publié `streak`.
 */
final class StreakRules
{
    public const int WEEK = 7;
    public const int SPORT_DAYS_PER_WEEK = 6;

    private const array RARITIES = [1 => 'COMMON', 2 => 'RARE', 3 => 'EPIC'];

    /**
     * @param array<string, true> $sportDays les jours sportifs, `AAAA-MM-JJ` local ; ceux d'après `$today` sont ignorés
     */
    public static function evaluate(array $sportDays, string $today): StreakState
    {
        $end = self::number($today);
        $days = [];
        foreach (array_keys($sportDays) as $day) {
            $number = self::number($day);
            if ($number <= $end) {
                $days[$number] = true;
            }
        }

        $anchor = null;
        $lastMiss = null;
        if ([] !== $days) {
            for ($day = min(array_keys($days)); $day <= $end; ++$day) {
                if (isset($days[$day])) {
                    $anchor ??= $day;
                } elseif (null !== $anchor && $day !== $end) {
                    if (null !== $lastMiss && $day - $lastMiss < self::WEEK) {
                        $anchor = $lastMiss = null;
                    } else {
                        $lastMiss = $day;
                    }
                }
            }
        }

        $weeks = 0;
        if (null !== $anchor) {
            for ($start = $anchor; $start <= $end; $start += self::WEEK) {
                if (self::countBetween($days, $start, $start + self::WEEK - 1) >= self::SPORT_DAYS_PER_WEEK) {
                    ++$weeks;
                }
            }
        }

        return new StreakState(
            null === $anchor ? null : self::date($anchor),
            null === $anchor ? 0 : $end - $anchor + 1,
            $weeks,
            self::countBetween($days, $end - self::WEEK + 1, $end),
            self::rarityOf($weeks + 1),
        );
    }

    /** La rareté du coffre de la semaine `$week` (1 = la première) : Légendaire à partir de la quatrième. */
    public static function rarityOf(int $week): string
    {
        return self::RARITIES[$week] ?? 'LEGENDARY';
    }

    /** @param array<int, true> $days */
    private static function countBetween(array $days, int $from, int $until): int
    {
        $count = 0;
        for ($day = $from; $day <= $until; ++$day) {
            $count += isset($days[$day]) ? 1 : 0;
        }

        return $count;
    }

    /** Un jour civil en entier, pour que les écarts soient des soustractions : en UTC, un jour fait toujours 86 400 secondes. */
    private static function number(string $date): int
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        \assert(false !== $day);

        return intdiv($day->getTimestamp(), 86400);
    }

    private static function date(int $number): string
    {
        return new DateTimeImmutable('@'.($number * 86400))->format('Y-m-d');
    }
}
