<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

enum LeagueMovement: string
{
    case Promoted = 'PROMOTED';
    case Maintained = 'MAINTAINED';
    case Relegated = 'RELEGATED';

    public static function between(LeagueTier $before, LeagueTier $after): self
    {
        return match ($after->order() <=> $before->order()) {
            1 => self::Promoted,
            -1 => self::Relegated,
            default => self::Maintained,
        };
    }
}
