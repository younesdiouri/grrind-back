<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Value object qui se sérialise en une seule chaîne. C'est le contrat que
 * `StringValueType` sait persister : un VO qui l'implémente devient stockable
 * sans écrire de type Doctrine complet.
 *
 * `fromString()` doit être total : soit il rend un VO valide, soit il lève.
 * Aucun VO à moitié valide ne doit exister — c'est tout l'intérêt.
 */
interface StringValue
{
    public static function fromString(string $value): static;

    public function toString(): string;
}
