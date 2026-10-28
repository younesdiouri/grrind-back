<?php

declare(strict_types=1);

namespace App\Community\Domain;

use DateTimeImmutable;
use DateTimeZone;

/** Une semaine civile de guilde peut durer 167 ou 169 heures lors du changement d'heure. */
final readonly class GuildWeek
{
    public const string TIMEZONE = 'Europe/Paris';

    private function __construct(public DateTimeImmutable $startsAt, public DateTimeImmutable $endsAt)
    {
    }

    public static function containing(DateTimeImmutable $now): self
    {
        $start = $now->setTimezone(new DateTimeZone(self::TIMEZONE))->modify('monday this week')->setTime(0, 0);

        return new self($start, $start->modify('+1 week'));
    }
}
