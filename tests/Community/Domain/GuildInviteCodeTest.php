<?php

declare(strict_types=1);

namespace App\Tests\Community\Domain;

use App\Community\Domain\Guild;
use App\Community\Domain\GuildInviteCode;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le code lui-même : sa forme, et sa seule lecture d'état.
 *
 * Pas de machine à états à tester, parce qu'il n'y en a pas — « expiré » n'est déclenché
 * par personne, c'est une comparaison de dates. Ce qui se teste, c'est que les deux causes
 * d'inutilisabilité soient traitées comme une seule.
 */
final class GuildInviteCodeTest extends TestCase
{
    private const string NOW = '2026-08-16T10:00:00+00:00';

    public function testTheCodeAvoidsCharactersThatLookAlike(): void
    {
        // Cent tirages : de quoi voir passer un caractère interdit s'il y en avait un, sans
        // faire de ce test une boucle qui coûte plus qu'elle ne prouve.
        for ($draw = 0; $draw < 100; ++$draw) {
            $code = self::mint()->code();

            self::assertSame(GuildInviteCode::LENGTH, \strlen($code));
            self::assertMatchesRegularExpression('/^['.GuildInviteCode::ALPHABET.']+$/', $code);
            self::assertDoesNotMatchRegularExpression('/[O0IL1]/', $code, 'Un caractère ambigu produit un « code invalide » que personne ne sait diagnostiquer.');
        }
    }

    public function testTwoCodesDiffer(): void
    {
        $codes = [];

        for ($draw = 0; $draw < 50; ++$draw) {
            $codes[] = self::mint()->code();
        }

        // Une collision sur cinquante tirages dans 31^8 possibilités dénoncerait un
        // générateur qui n'en est pas un — c'est le seul défaut qu'un test peut voir.
        self::assertCount(50, array_unique($codes));
    }

    public function testAFreshCodeIsUsable(): void
    {
        $code = self::mint();

        self::assertTrue($code->isUsableAt(new DateTimeImmutable(self::NOW)));
        self::assertNull($code->revokedAt());
    }

    public function testAnExpiredCodeIsNotUsable(): void
    {
        $code = self::mint();

        self::assertFalse($code->isUsableAt(new DateTimeImmutable('2026-08-18T11:00:00+00:00')));
    }

    public function testTheCodeDiesExactlyAtItsExpiry(): void
    {
        // La borne se teste, sinon elle se choisit par accident : « jusqu'à » et « jusqu'à
        // inclus » diffèrent d'une seconde, et c'est toujours celle-là qui casse.
        $code = self::mint();

        self::assertFalse($code->isUsableAt(new DateTimeImmutable('2026-08-18T10:00:00+00:00')));
        self::assertTrue($code->isUsableAt(new DateTimeImmutable('2026-08-18T09:59:59+00:00')));
    }

    public function testARevokedCodeIsNotUsableEvenBeforeItsExpiry(): void
    {
        $code = self::mint();

        $code->revoke(new DateTimeImmutable('2026-08-16T12:00:00+00:00'));

        self::assertFalse($code->isUsableAt(new DateTimeImmutable('2026-08-16T13:00:00+00:00')));
    }

    public function testRevokingTwiceKeepsTheFirstDate(): void
    {
        $code = self::mint();
        $first = new DateTimeImmutable('2026-08-16T12:00:00+00:00');

        $code->revoke($first);
        $code->revoke(new DateTimeImmutable('2026-08-16T18:00:00+00:00'));

        self::assertEquals($first, $code->revokedAt(), 'Ce qui compte est que le code soit mort, pas quand on l\'a redit.');
    }

    private static function mint(): GuildInviteCode
    {
        return GuildInviteCode::issueFor(
            Guild::found('Les Lève-Tôt', Uuid::v7(), new DateTimeImmutable(self::NOW)),
            new DateInterval('PT48H'),
            new DateTimeImmutable(self::NOW),
        );
    }
}
