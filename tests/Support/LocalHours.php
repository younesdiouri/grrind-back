<?php

declare(strict_types=1);

namespace App\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Choisir un fuseau pour qu'il y soit une heure donnée **maintenant**.
 *
 * Les heures calmes (#133) se lisent dans le fuseau du destinataire, donc les éprouver
 * demande un joueur dont il est 2h du matin chez lui — quelle que soit l'heure à laquelle la
 * suite tourne. Un fuseau fixé une fois pour toutes ferait passer le test le matin et échouer
 * l'après-midi, ce qui est la pire forme d'échec : celle qu'on finit par relancer.
 *
 * Extrait de `GuildActivityNotifierTest` au #194, où les annonces de Risālāt ont eu besoin de
 * la même bascule. Deux copies de cette table auraient divergé au premier fuseau corrigé.
 */
trait LocalHours
{
    /**
     * Un fuseau IANA réel sans heure d'été par décalage UTC entier, pour
     * {@see self::timezoneShiftingUtcHourTo()}.
     *
     * @var array<int, string>
     */
    private const array ZONE_BY_UTC_OFFSET = [
        0 => 'UTC',
        1 => 'Africa/Lagos',
        2 => 'Africa/Johannesburg',
        3 => 'Africa/Nairobi',
        4 => 'Asia/Dubai',
        5 => 'Asia/Karachi',
        6 => 'Asia/Dhaka',
        7 => 'Asia/Bangkok',
        8 => 'Asia/Shanghai',
        9 => 'Asia/Tokyo',
        10 => 'Australia/Brisbane',
        11 => 'Pacific/Noumea',
        12 => 'Pacific/Wallis',
        13 => 'Pacific/Tongatapu',
        14 => 'Pacific/Kiritimati',
        -1 => 'Atlantic/Cape_Verde',
        -2 => 'America/Noronha',
        -3 => 'America/Sao_Paulo',
        -4 => 'America/La_Paz',
        -5 => 'America/Bogota',
        -6 => 'America/Guatemala',
        -7 => 'America/Phoenix',
        -8 => 'Pacific/Pitcairn',
        -9 => 'Pacific/Gambier',
        -10 => 'Pacific/Honolulu',
        -11 => 'Pacific/Pago_Pago',
    ];

    /**
     * Le fuseau dont l'heure locale vaut `$targetLocalHour` **à l'instant où le test
     * tourne** — pas un fuseau fixé une fois pour toutes, dont l'écart avec l'heure réelle
     * changerait selon le jour de l'exécution et rendrait ce test dépendant de l'heure à
     * laquelle la suite passe.
     */
    private static function timezoneShiftingUtcHourTo(int $targetLocalHour): string
    {
        $utcHour = (int) new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('G');
        $offset = ($targetLocalHour - $utcHour + 24) % 24;

        if ($offset > 14) {
            $offset -= 24;
        }

        return self::ZONE_BY_UTC_OFFSET[$offset];
    }
}
