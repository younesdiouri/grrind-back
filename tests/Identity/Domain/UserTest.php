<?php

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\Role;
use App\Identity\Domain\User;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * L'entité est désormais le `UserInterface` du firewall : ce qui est vérifié ici,
 * c'est le contrat que le composant Security attend d'elle, plus la normalisation
 * de l'adresse — qui donne son sens à l'index unique.
 */
final class UserTest extends TestCase
{
    #[DataProvider('spellings')]
    public function testNormalisesTheEmail(string $written, string $stored): void
    {
        self::assertSame($stored, self::bob($written)->email());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellings(): iterable
    {
        yield 'déjà normalisée' => ['bob@grrind.app', 'bob@grrind.app'];
        yield 'majuscules' => ['BOB@GRRIND.APP', 'bob@grrind.app'];
        yield 'casse mélangée' => ['Bob@Grrind.App', 'bob@grrind.app'];
        yield 'espaces autour' => ['  bob@grrind.app  ', 'bob@grrind.app'];
        // Un clavier iOS ajoute volontiers une majuscule et une espace : les deux
        // ensemble ne doivent pas ouvrir un second compte.
        yield 'les deux' => [' Bob@Grrind.app ', 'bob@grrind.app'];
    }

    public function testTheSecurityIdentifierIsTheUuidNotTheEmail(): void
    {
        $user = self::bob();

        self::assertSame($user->id()->toRfc4122(), $user->getUserIdentifier());
        self::assertStringNotContainsString('@', $user->getUserIdentifier());
    }

    public function testEveryAccountIsAtLeastAPlayer(): void
    {
        // ROLE_USER n'est jamais stocké : Symfony veut qu'il soit toujours rendu.
        self::assertSame([Role::User->value], self::bob()->getRoles());
    }

    public function testGrantsARoleWithoutDuplicatingIt(): void
    {
        $user = self::bob();
        $user->grant(Role::Admin);
        $user->grant(Role::Admin);
        $user->grant(Role::User);

        self::assertSame([Role::User->value, Role::Admin->value], $user->getRoles());
    }

    public function testHasNoPasswordUntilOneIsSet(): void
    {
        // Cas du social sign-in : le compte existe, il n'a jamais eu de mot de passe.
        self::assertNull(self::bob()->getPassword());
    }

    public function testTrimsTheDisplayName(): void
    {
        self::assertSame('Bob', self::bob(displayName: '  Bob  ')->displayName());

        $user = self::bob();
        $user->rename('  Bobby ');

        self::assertSame('Bobby', $user->displayName());
    }

    private static function bob(string $email = 'bob@grrind.app', string $displayName = 'Bob'): User
    {
        return User::register(
            $email,
            $displayName,
            Timezone::fromString('Europe/Paris'),
            new DateTimeImmutable('2026-08-10T09:00:00+02:00'),
        );
    }
}
