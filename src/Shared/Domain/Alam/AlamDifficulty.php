<?php

declare(strict_types=1);

namespace App\Shared\Domain\Alam;

enum AlamDifficulty: string
{
    case Easy = 'EASY';
    case Intermediate = 'INTERMEDIATE';
    case Hard = 'HARD';

    public function position(): int
    {
        return match ($this) {
            self::Easy => 0, self::Intermediate => 1, self::Hard => 2
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Easy => 'Facile', self::Intermediate => 'Intermédiaire', self::Hard => 'Difficile'
        };
    }
}
