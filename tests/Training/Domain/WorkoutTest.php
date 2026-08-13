<?php

declare(strict_types=1);

namespace App\Tests\Training\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use App\Training\Domain\Workout;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * L'agrégat n'a plus de transitions à valider depuis le retrait du chronomètre (#85). Ce
 * qui reste à garantir tient en une phrase : **la durée se calcule, elle ne se recopie
 * pas**.
 *
 * Sans infra : c'est du domaine pur, et ça doit le rester.
 */
final class WorkoutTest extends TestCase
{
    public function testTheDurationComesFromTheBoundsAndNotFromTheCaller(): void
    {
        $workout = self::recorded('2026-08-12T09:00:00+00:00', '2026-08-12T09:45:00+00:00');

        self::assertSame(2700, $workout->durationSeconds());
    }

    /**
     * Le fournisseur date, le serveur constate. `createdAt` est le seul instant que le
     * serveur pose lui-même, et il diverge de `startedAt` dès qu'un import rattrape le
     * passé — c'est-à-dire toujours.
     */
    public function testTheProviderBoundsAreKeptAsTheyAreAndCreatedAtIsOurs(): void
    {
        $startedAt = new DateTimeImmutable('2026-08-01T06:30:00+02:00');
        $endedAt = new DateTimeImmutable('2026-08-01T07:15:00+02:00');
        $now = new DateTimeImmutable('2026-08-13T20:00:00+00:00');

        $workout = Workout::record(Uuid::v7(), Discipline::Running, SessionSource::HealthKit, $startedAt, $endedAt, $now);

        self::assertSame($startedAt->getTimestamp(), $workout->startedAt()->getTimestamp());
        self::assertSame($endedAt->getTimestamp(), $workout->endedAt()->getTimestamp());
        self::assertSame($now->getTimestamp(), $workout->createdAt()->getTimestamp());
    }

    /**
     * Une montre qui rend des bornes inversées — un fuseau mal appliqué suffit — donne un
     * workout sans valeur, pas une durée négative. Le ledger est append-only : une ligne
     * empoisonnée y reste.
     */
    public function testInvertedBoundsGiveNothingRatherThanANegativeDuration(): void
    {
        $workout = self::recorded('2026-08-12T10:00:00+00:00', '2026-08-12T09:00:00+00:00');

        self::assertSame(0, $workout->durationSeconds());
    }

    /**
     * Le niveau de confiance se déduit de la source et ne se passe pas en argument :
     * personne ne peut déclarer une séance vérifiée par un fournisseur.
     */
    #[DataProvider('sources')]
    public function testTrustIsDerivedFromTheSource(SessionSource $source, TrustLevel $expected): void
    {
        $workout = Workout::record(
            Uuid::v7(),
            Discipline::Running,
            $source,
            new DateTimeImmutable('2026-08-12T09:00:00+00:00'),
            new DateTimeImmutable('2026-08-12T09:30:00+00:00'),
            new DateTimeImmutable('2026-08-12T10:00:00+00:00'),
        );

        self::assertSame($expected, $workout->trust());
    }

    /**
     * @return iterable<string, array{SessionSource, TrustLevel}>
     */
    public static function sources(): iterable
    {
        yield 'déclarée' => [SessionSource::ManualTimer, TrustLevel::Declared];
        yield 'vérifiée par le fournisseur' => [SessionSource::HealthKit, TrustLevel::ProviderVerified];
    }

    /**
     * Rien ne les remplit encore : l'import qui les porte arrive au #88. Ce test fige
     * l'état de départ, pour que « pas mesuré » ne devienne pas « zéro » par accident.
     */
    public function testMeasurementsStartUnmeasuredAndNotAtZero(): void
    {
        $workout = self::recorded('2026-08-12T09:00:00+00:00', '2026-08-12T09:30:00+00:00');

        self::assertNull($workout->distanceMeters());
        self::assertNull($workout->calories());
        self::assertNull($workout->elevationGainMeters());
        self::assertNull($workout->averageHeartRate());
        self::assertNull($workout->externalId());
    }

    private static function recorded(string $startedAt, string $endedAt): Workout
    {
        return Workout::record(
            Uuid::v7(),
            Discipline::Running,
            SessionSource::HealthKit,
            new DateTimeImmutable($startedAt),
            new DateTimeImmutable($endedAt),
            new DateTimeImmutable('2026-08-13T00:00:00+00:00'),
        );
    }
}
