<?php

declare(strict_types=1);

namespace App\Training\Domain;

use App\Shared\Domain\LocalDay;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;

/**
 * Calendrier civil des stats de volume (#352), indépendant de la semaine Alam.
 * Symfony fournit l'horloge, pas ces règles produit : elles reprennent statsWindow()
 * de l'app. Le fuseau du profil remplace celui du téléphone. Si le DST supprime
 * minuit, on garde le début valide du jour et réancre les bornes suivantes à minuit,
 * plutôt que propager 01 h comme les mutations Date JS de l'app.
 *
 * Toutes les fins sont exclues. On observe jusqu'au minuit qui termine aujourd'hui,
 * puis compare autant de jours civils depuis le début de la période précédente,
 * sans dépasser sa fin. Ni 86 400 secondes par jour, ni alignement des dates :
 * le 1er mars bissextile compare 61 jours, même si cela atteint le 3 mars précédent.
 */
final readonly class WorkoutStatsWindow
{
    private function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public DateTimeImmutable $observedEnd,
        public DateTimeImmutable $previousStart,
        public DateTimeImmutable $previousEnd,
        public int $days,
    ) {
    }

    public static function containing(StatsPeriod $period, DateTimeImmutable $now, Timezone $timezone): self
    {
        $today = LocalDay::containing($now, $timezone);
        $midnight = $today->startsAt->setTimezone($timezone->toDateTimeZone());
        $start = match ($period) {
            StatsPeriod::Week => $midnight->modify('-'.((int) $midnight->format('N') - 1).' days'),
            StatsPeriod::Month => $midnight->setDate((int) $midnight->format('Y'), (int) $midnight->format('m'), 1),
            StatsPeriod::Year => $midnight->setDate((int) $midnight->format('Y'), 1, 1),
        };
        $start = $start->setTime(0, 0);
        $end = $start->modify('+1 '.$period->value)->setTime(0, 0);
        $observedEnd = $today->endsAt->setTimezone($timezone->toDateTimeZone());
        // Si minuit n'existe pas, le début vaut 01 h : compter les dates, pas les heures.
        $days = 0;
        for ($day = $start; $day < $observedEnd; $day = $day->modify('+1 day')->setTime(0, 0)) {
            ++$days;
        }
        $previousStart = $start->modify('-1 '.$period->value)->setTime(0, 0);

        return new self($start, $end, $observedEnd, $previousStart, min($start, $previousStart->modify('+'.$days.' days')->setTime(0, 0)), $days);
    }
}
