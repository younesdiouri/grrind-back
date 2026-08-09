<?php

declare(strict_types=1);

namespace App\Tests\Shared\Domain;

use App\Shared\Domain\Timezone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimezoneTest extends TestCase
{
    public function testAcceptsAnIanaIdentifier(): void
    {
        self::assertSame('Europe/Paris', Timezone::fromString('Europe/Paris')->toString());
    }

    #[DataProvider('invalidIdentifiers')]
    public function testRejectsAnythingElse(string $value): void
    {
        self::assertFalse(Timezone::isValid($value));

        $this->expectException(InvalidArgumentException::class);
        Timezone::fromString($value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'chaîne vide' => [''];
        yield 'fuseau inventé' => ['Europe/Atlantis'];
        // Les abréviations sont ambiguës (CEST vaut deux offsets selon la saison)
        // et les offsets fixes ignorent l'heure d'été : ni l'un ni l'autre ne
        // permet de dire quand commence le lendemain pour le user.
        yield 'abréviation' => ['CEST'];
        yield 'offset fixe' => ['+02:00'];
    }

    public function testKeepsTheCaseItWasGiven(): void
    {
        // Les identifiants IANA sont sensibles à la casse : on ne « répare » pas
        // une entrée douteuse, on la refuse.
        $this->expectException(InvalidArgumentException::class);
        Timezone::fromString('europe/paris');
    }

    public function testComparesByIdentifier(): void
    {
        self::assertTrue(Timezone::fromString('UTC')->equals(Timezone::utc()));
        self::assertFalse(Timezone::utc()->equals(Timezone::fromString('Europe/Paris')));
    }
}
