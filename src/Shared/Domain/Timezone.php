<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use DateTimeZone;
use InvalidArgumentException;

/**
 * Fuseau IANA du joueur. Le stockage est en UTC partout, mais le streak et les plafonds
 * quotidiens se calculent dans *ce* fuseau : sans lui, un joueur à Tokyo perdrait sa
 * série à 9 h du matin. C'est un attribut de profil, jamais une déduction depuis l'IP.
 */
final readonly class Timezone implements StringValue
{
    private function __construct(public string $identifier)
    {
    }

    public static function fromString(string $value): static
    {
        if (!\in_array($value, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException(\sprintf('Fuseau horaire IANA inconnu : "%s".', $value));
        }

        return new self($value);
    }

    public static function utc(): self
    {
        return new self('UTC');
    }

    public static function isValid(string $value): bool
    {
        return \in_array($value, DateTimeZone::listIdentifiers(), true);
    }

    public function toString(): string
    {
        return $this->identifier;
    }

    public function toDateTimeZone(): DateTimeZone
    {
        return new DateTimeZone($this->identifier);
    }

    public function equals(self $other): bool
    {
        return $this->identifier === $other->identifier;
    }
}
