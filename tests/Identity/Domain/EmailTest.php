<?php

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\Email;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    #[DataProvider('equivalentSpellings')]
    public function testNormalisesSoTheUniqueIndexMeansSomething(string $input): void
    {
        self::assertSame('bob@grrind.app', Email::fromString($input)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentSpellings(): iterable
    {
        yield 'déjà normalisée' => ['bob@grrind.app'];
        yield 'majuscules' => ['Bob@Grrind.app'];
        yield 'espaces autour' => ['  bob@grrind.app  '];
        yield 'les deux' => [" BOB@GRRIND.APP\n"];
    }

    #[DataProvider('invalidAddresses')]
    public function testRejectsWhatCannotReceiveMail(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        Email::fromString($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAddresses(): iterable
    {
        yield 'vide' => [''];
        yield 'sans arobase' => ['bob.grrind.app'];
        yield 'sans domaine' => ['bob@'];
        yield 'avec espace interne' => ['bo b@grrind.app'];
        yield 'trop longue' => [str_repeat('a', 175).'@grrind.app'];
    }

    public function testComparesAfterNormalisation(): void
    {
        self::assertTrue(Email::fromString('Bob@grrind.app')->equals(Email::fromString('bob@GRRIND.app')));
    }
}
