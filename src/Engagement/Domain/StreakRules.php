<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use DateTimeImmutable;

/**
 * Le streak de l'assiduité (#346) : ouvrir l'app une fois par jour fait la série, le sport
 * fait le palier du coffre de la semaine.
 *
 * Il remplace la régularité sportive du #286 (six jours de 30 minutes sur sept) : trop dure
 * à tenir, elle cassait presque chaque semaine. Une semaine civile — lundi au dimanche, dans
 * le fuseau du joueur — pointée sept jours sur sept rapporte un coffre ; le nombre de jours
 * où le joueur a fait au moins une séance créditée en fixe le palier. Des **jours**, pas des
 * séances : six séances courtes le dimanche ne valent pas une semaine de sport.
 *
 * Les seuils sont la spec, pas un réglage : seul le coffre de chaque palier s'administre,
 * dans le réglage publié `streak`.
 */
final class StreakRules
{
    public const int WEEK = 7;

    /**
     * Jours pointés consécutifs jusqu'à aujourd'hui. Aujourd'hui pas encore pointé ne casse
     * rien — la journée n'est pas finie — on compte alors jusqu'à hier.
     *
     * @param array<string, true> $checkIns les jours pointés, `AAAA-MM-JJ` local
     */
    public static function days(array $checkIns, string $today): int
    {
        $day = new DateTimeImmutable($today);
        if (!isset($checkIns[$today])) {
            $day = $day->modify('-1 day');
        }
        $days = 0;
        while (isset($checkIns[$day->format('Y-m-d')])) {
            ++$days;
            $day = $day->modify('-1 day');
        }

        return $days;
    }

    /** Le palier du coffre d'une semaine, selon ses jours avec au moins une séance. */
    public static function rarityFor(int $sportDays): string
    {
        return match (true) {
            $sportDays >= 6 => 'LEGENDARY',
            $sportDays >= 3 => 'RARE',
            default => 'COMMON',
        };
    }

    /** Le lundi de la semaine qui contient `$day`. */
    public static function weekStart(string $day): string
    {
        return new DateTimeImmutable($day)->modify('monday this week')->format('Y-m-d');
    }
}
