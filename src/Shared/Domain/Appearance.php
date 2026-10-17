<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** Identifiants de présentation ouverts : ajouter un avatar ne demande pas de nouvelle enum. */
final class Appearance
{
    public const string DEFAULT = 'STRENGTH_V1';

    public static function default(): string
    {
        return self::DEFAULT;
    }

    public static function fromSnapshot(mixed $value): string
    {
        // Phase de dev : la DA Murīd retirée est remplacée visuellement, sans toucher aux faits.
        return \is_string($value) && 'MURID' !== $value && 1 === preg_match('/^[A-Z][A-Z0-9_]{0,63}$/', $value) ? $value : self::DEFAULT;
    }
}
