<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Langues que le produit livre réellement. Le choix vit sur le compte afin qu'une
 * notification asynchrone garde la langue du destinataire, sans dépendre du téléphone qui
 * l'a créée.
 */
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
