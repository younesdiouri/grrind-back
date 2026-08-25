<?php

declare(strict_types=1);

namespace App\Tests\Progression\Domain;

use App\Progression\Domain\LevelCurve;
use App\Progression\Domain\ProgressionSnapshot;
use App\Shared\Domain\Activity\AttributeGains;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le snapshot vu comme ce qu'il est : une projection, pas un compteur.
 *
 * Rien ici ne touche la base — ce qui se démontre est que `retotal()` **dérive** tout du
 * total et de la répartition qu'on lui donne (#160), et qu'il sait dire les niveaux
 * franchis. Le verrou et la concurrence, eux, se prouvent contre PostgreSQL dans
 * `GrantXpTest`.
 */
final class ProgressionSnapshotTest extends TestCase
{
    private const string NOW = '2026-08-11T10:00:00+00:00';

    public function testAFreshPlayerStartsAtLevelOne(): void
    {
        $snapshot = self::untouched();

        self::assertSame(0, $snapshot->totalXp());
        self::assertSame(1, $snapshot->level());
        self::assertSame(0, $snapshot->xpIntoLevel());
        self::assertSame(100, $snapshot->xpToNextLevel());
        self::assertSame(0, $snapshot->earnedSkillPoints());
        self::assertEquals(new AttributeGains(0, 0, 0, 0), $snapshot->attributes());
    }

    public function testRetotalDerivesEverythingFromTheTotal(): void
    {
        $snapshot = self::untouched();

        $snapshot->retotal(220, self::onStrength(220), self::curve(), new DateTimeImmutable('2026-08-11T12:00:00+00:00'));

        self::assertSame(220, $snapshot->totalXp());
        self::assertSame(2, $snapshot->level());
        self::assertSame(120, $snapshot->xpIntoLevel());
        self::assertSame(80, $snapshot->xpToNextLevel());
        self::assertSame(1, $snapshot->earnedSkillPoints());
        self::assertSame('2026-08-11T12:00:00+00:00', $snapshot->updatedAt()->format(\DATE_ATOM));
    }

    /**
     * Le cœur du #160 : les quatre caractéristiques suivent la répartition qu'on leur donne,
     * pas une reprojection depuis le total — `ProgressionSnapshot` n'a aucune règle de
     * répartition, c'est `AttributeSplit` qui l'a déjà appliquée avant d'atteindre le
     * snapshot.
     */
    public function testAttributesAreRecopiedFromTheGivenSplitRatherThanRederived(): void
    {
        $snapshot = self::untouched();

        $snapshot->retotal(121, new AttributeGains(50, 40, 20, 11), self::curve(), self::now());

        self::assertEquals(new AttributeGains(50, 40, 20, 11), $snapshot->attributes());
    }

    /**
     * Un ledger connu, rejoué en deux temps : un crédit puis son annulation exacte — le
     * même scénario que produirait une invalidation de séance. Le total redescend à zéro,
     * et **les quatre caractéristiques avec lui**, pas seulement l'XP : c'est précisément
     * ce qu'une comparaison qui n'aurait regardé que `totalXp` laisserait passer.
     */
    public function testAReversalLowersAllFourAttributesNotOnlyTheTotal(): void
    {
        $snapshot = self::untouched();

        $snapshot->retotal(121, new AttributeGains(50, 40, 20, 11), self::curve(), self::now());
        self::assertEquals(new AttributeGains(50, 40, 20, 11), $snapshot->attributes());

        // `retotal()` reçoit toujours la somme **totale** du ledger, jamais un delta — même
        // règle que pour `totalXp`. Après un crédit intégralement annulé, la somme du
        // ledger retombe à zéro sur les quatre colonnes, et c'est cette somme-là qu'on lui
        // repasse : `GrantXpHandler` et `RebuildSnapshotsHandler` la relisent au ledger à
        // chaque appel, ils ne la déduisent jamais de l'état précédent.
        self::assertSame([], $snapshot->retotal(0, new AttributeGains(0, 0, 0, 0), self::curve(), self::now()));

        self::assertSame(0, $snapshot->totalXp());
        self::assertEquals(new AttributeGains(0, 0, 0, 0), $snapshot->attributes());
    }

    public function testAnnouncesTheLevelCrossed(): void
    {
        $snapshot = self::untouched();

        self::assertSame([2], $snapshot->retotal(150, self::onStrength(150), self::curve(), self::now()));
    }

    public function testAnnouncesEveryLevelWhenSeveralAreCrossedAtOnce(): void
    {
        // Le cas normal après une pause, pas l'exception. Le client les anime un par un :
        // un booléen « a monté de niveau » lui ferait en avaler deux en silence.
        $snapshot = self::untouched();

        self::assertSame([2, 3, 4], $snapshot->retotal(600, self::onStrength(600), self::curve(), self::now()));
        self::assertSame(4, $snapshot->earnedSkillPoints());
    }

    public function testAnnouncesNothingWhenTheLevelDoesNotMove(): void
    {
        $snapshot = self::untouched();
        $snapshot->retotal(150, self::onStrength(150), self::curve(), self::now());

        self::assertSame([], $snapshot->retotal(180, self::onStrength(180), self::curve(), self::now()));
    }

    public function testAReversalLowersTheLevelWithoutAnnouncingAnything(): void
    {
        $snapshot = self::untouched();
        $snapshot->retotal(350, self::onStrength(350), self::curve(), self::now());
        self::assertSame(3, $snapshot->level());

        // Une annulation ramène le joueur à son niveau réel — mais elle ne « fait pas
        // descendre » un niveau au sens du jeu, il n'y a rien à animer.
        self::assertSame([], $snapshot->retotal(120, self::onStrength(120), self::curve(), self::now()));

        self::assertSame(2, $snapshot->level());
        self::assertSame(1, $snapshot->earnedSkillPoints());
    }

    public function testTheProjectionIsIdempotent(): void
    {
        // Deux fois le même total ne double rien : c'est ce qui fait qu'une reconstruction
        // (#20) peut rejouer autant de fois qu'elle veut.
        $snapshot = self::untouched();
        $snapshot->retotal(350, self::onStrength(350), self::curve(), self::now());

        self::assertSame([], $snapshot->retotal(350, self::onStrength(350), self::curve(), self::now()));
        self::assertSame(3, $snapshot->level());
        self::assertSame(2, $snapshot->earnedSkillPoints());
    }

    public function testTheTopOfTheCurveHasNoNextLevel(): void
    {
        $snapshot = self::untouched();
        $snapshot->retotal(5_000, self::onStrength(5_000), self::curve(), self::now());

        self::assertSame(4, $snapshot->level());
        self::assertNull($snapshot->xpToNextLevel());
        // L'XP continue de s'accumuler au-delà : le ledger ne s'arrête pas parce que la
        // courbe s'arrête, et la suite de la courbe se rallongera dans le YAML.
        self::assertSame(4400, $snapshot->xpIntoLevel());
        self::assertSame(5_000, $snapshot->totalXp());
    }

    private static function untouched(): ProgressionSnapshot
    {
        return ProgressionSnapshot::untouched(Uuid::v7(), self::curve(), self::now());
    }

    /** Tout sur `strength`, pour les tests qui portent sur le niveau et non la répartition. */
    private static function onStrength(int $amount): AttributeGains
    {
        return new AttributeGains($amount, 0, 0, 0);
    }

    private static function curve(): LevelCurve
    {
        return new LevelCurve(LevelCurveTest::fixture());
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW);
    }
}
