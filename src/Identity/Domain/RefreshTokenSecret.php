<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use InvalidArgumentException;

/**
 * La valeur brute d'un refresh token — celle que le client garde et renvoie.
 *
 * Elle n'est jamais stockée : la base ne contient que son SHA-256. Une fuite du
 * dump ne donne donc aucun jeton utilisable. Pas de sel ni de bcrypt ici, c'est
 * inutile : contrairement à un mot de passe, ce secret fait 256 bits d'entropie
 * réelle, il n'y a rien à deviner.
 */
final readonly class RefreshTokenSecret
{
    private const int BYTES = 32;

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '='));
    }

    public static function fromString(string $value): self
    {
        if ('' === $value) {
            throw new InvalidArgumentException('Refresh token vide.');
        }

        return new self($value);
    }

    public function hash(): string
    {
        return hash('sha256', $this->value);
    }
}
