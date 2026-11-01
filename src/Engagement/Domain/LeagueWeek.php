<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use DateTimeImmutable;
use DateTimeZone;

/** La grille commune des ligues ; les changements d'heure sont ceux de Paris. */
final readonly class LeagueWeek
{
    public const string TIMEZONE = 'Europe/Paris';

    private function __construct(public DateTimeImmutable $startsAt, public DateTimeImmutable $endsAt)
    {
    }

    public static function containing(DateTimeImmutable $instant): self
    {
        $start = $instant->setTimezone(new DateTimeZone(self::TIMEZONE))->modify('monday this week')->setTime(0, 0);

        return new self($start, $start->modify('+1 week'));
    }
}
