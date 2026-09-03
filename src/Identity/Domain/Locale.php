<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/** Langues réellement livrées, persistées sur le profil du joueur. */
enum Locale: string
{
    case English = 'en';
    case French = 'fr';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }
}
