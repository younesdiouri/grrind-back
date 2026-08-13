<?php

declare(strict_types=1);

namespace App\Tests\Shared\UI\Http;

use App\Shared\UI\Http\Cursor;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le curseur des deux historiques. Sans infra : c'est de l'encodage, et son seul risque est
 * de laisser passer une chaîne qui n'en est pas un.
 */
final class CursorTest extends TestCase
{
    public function testSurvivesTheRoundTrip(): void
    {
        $at = new DateTimeImmutable('2026-07-15T08:30:00+02:00');
        $id = Uuid::v7();

        $decoded = Cursor::tryFrom(Cursor::of($at, $id)->encoded());

        self::assertInstanceOf(Cursor::class, $decoded);
        self::assertSame($at->getTimestamp(), $decoded->at->getTimestamp());
        self::assertTrue($id->equals($decoded->id));
    }

    /**
     * Il voyage dans une query string : ni `+`, ni `/`, ni `=` — sans quoi il faudrait
     * l'encoder une seconde fois, et un client qui l'oublierait paginerait de travers.
     */
    public function testIsSafeInAQueryStringWithoutFurtherEncoding(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $encoded = Cursor::of(new DateTimeImmutable(\sprintf('-%d hours', $i)), Uuid::v7())->encoded();

            self::assertSame($encoded, urlencode($encoded));
        }
    }

    /**
     * Un curseur bricolé à la main rend `null`, et l'appelant en fait un 422. Ce qu'il ne
     * doit surtout pas faire, c'est passer pour un curseur valide qui ne désignerait rien :
     * la page vide qui s'ensuivrait ferait croire au client qu'il est au bout.
     */
    #[DataProvider('nonsense')]
    public function testRefusesWhatIsNotACursor(string $encoded): void
    {
        self::assertNull(Cursor::tryFrom($encoded));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonsense(): iterable
    {
        yield 'vide' => [''];
        yield 'du texte' => ['pas-un-curseur'];
        yield 'un UUID nu, comme avant' => ['0198f3a2-1c4b-7000-8000-000000000000'];
        yield 'du base64 sans séparateur' => [rtrim(strtr(base64_encode('2026-07-15T08:30:00+00:00'), '+/', '-_'), '=')];
        yield 'une date illisible' => [self::raw('hier|0198f3a2-1c4b-7000-8000-000000000000')];
        yield 'un identifiant qui n\'en est pas un' => [self::raw('2026-07-15T08:30:00+00:00|pas-un-uuid')];
        yield 'trois parties' => [self::raw('2026-07-15T08:30:00+00:00|0198f3a2-1c4b-7000-8000-000000000000|de-trop')];
    }

    private static function raw(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}
