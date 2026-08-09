<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\StringValue;
use InvalidArgumentException;

/**
 * Adresse e-mail normalisée. La normalisation (trim + minuscules) est faite ici et
 * nulle part ailleurs : c'est elle qui donne du sens à l'index unique. Sans elle,
 * « Bob@x.fr » et « bob@x.fr » créeraient deux comptes.
 *
 * La RFC autorise une partie locale sensible à la casse ; aucun fournisseur sérieux
 * ne s'en sert, et la respecter coûterait bien plus cher en support que ça ne rapporte.
 */
final readonly class Email implements StringValue
{
    public const int MAX_LENGTH = 180;

    private function __construct(public string $address)
    {
    }

    public static function fromString(string $value): static
    {
        $normalized = strtolower(trim($value));

        if (self::MAX_LENGTH < \strlen($normalized)) {
            throw new InvalidArgumentException(\sprintf('Adresse e-mail trop longue (%d caractères max).', self::MAX_LENGTH));
        }

        if (false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(\sprintf('Adresse e-mail invalide : "%s".', $value));
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->address;
    }

    public function equals(self $other): bool
    {
        return $this->address === $other->address;
    }
}
