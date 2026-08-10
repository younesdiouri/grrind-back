<?php

declare(strict_types=1);

namespace App\Tests\Training\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use App\Training\Domain\Exception\SessionNotActive;
use App\Training\Domain\SessionStatus;
use App\Training\Domain\TrainingSession;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le domaine se teste sans base, sans conteneur et sans horloge réelle : c'est la
 * contrepartie du `$now` passé en paramètre à chaque transition.
 */
final class TrainingSessionTest extends TestCase
{
    private const string START = '2026-08-10T09:00:00+02:00';

    public function testStartsActiveOnTheServerClock(): void
    {
        $now = new DateTimeImmutable(self::START);
        $session = TrainingSession::start(Uuid::v7(), Discipline::Running, $now);

        self::assertSame(SessionStatus::Active, $session->status());
        self::assertEquals($now, $session->startedAt());
        self::assertEquals($now, $session->createdAt());
        self::assertNull($session->endedAt());
        self::assertNull($session->durationSeconds());
    }

    public function testATimerSessionIsOnlyDeclared(): void
    {
        // La v1 n'a rien à vérifier : le joueur déclare, on le croit, et les garde-fous
        // anti-abus font le reste. Le jour où Strava arrive, la même séance change de
        // crédit sans que le modèle bouge.
        $session = self::started();

        self::assertSame(SessionSource::ManualTimer, $session->source());
        self::assertSame(TrustLevel::Declared, $session->trust());
    }

    #[DataProvider('sources')]
    public function testTrustFollowsFromTheSource(SessionSource $source, TrustLevel $trust): void
    {
        self::assertSame($trust, $source->defaultTrust());
    }

    /**
     * @return iterable<string, array{SessionSource, TrustLevel}>
     */
    public static function sources(): iterable
    {
        yield 'chronomètre' => [SessionSource::ManualTimer, TrustLevel::Declared];
        yield 'Strava' => [SessionSource::Strava, TrustLevel::ProviderVerified];
        yield 'HealthKit' => [SessionSource::HealthKit, TrustLevel::ProviderVerified];
    }

    public function testCompletingFreezesTheDurationMeasuredByTheServer(): void
    {
        $session = self::started();
        $session->complete(new DateTimeImmutable('2026-08-10T09:45:30+02:00'));

        self::assertSame(SessionStatus::Completed, $session->status());
        self::assertSame(2730, $session->durationSeconds());
        self::assertEquals(new DateTimeImmutable('2026-08-10T09:45:30+02:00'), $session->endedAt());
    }

    public function testAbandoningClosesTheSessionWithoutErasingIt(): void
    {
        $session = self::started();
        $session->abandon(new DateTimeImmutable('2026-08-10T09:02:00+02:00'));

        self::assertSame(SessionStatus::Abandoned, $session->status());
        // La durée est renseignée même sans XP à la clé : c'est elle qui dira si le
        // cooldown court, et l'historique garde la trace de l'abandon.
        self::assertSame(120, $session->durationSeconds());
        self::assertNotNull($session->endedAt());
    }

    public function testTheDurationIgnoresTheClientTimezone(): void
    {
        // Même instant écrit dans un autre fuseau : la durée ne bouge pas.
        $session = self::started();
        $session->complete(new DateTimeImmutable('2026-08-10T07:30:00+00:00'));

        self::assertSame(1800, $session->durationSeconds());
    }

    public function testAClockGoingBackwardsCannotProduceANegativeDuration(): void
    {
        $session = self::started();
        $session->complete(new DateTimeImmutable('2026-08-10T08:59:00+02:00'));

        self::assertSame(0, $session->durationSeconds());
    }

    /**
     * @param callable(TrainingSession, DateTimeImmutable): void $first
     * @param callable(TrainingSession, DateTimeImmutable): void $again
     */
    #[DataProvider('closedTwice')]
    public function testAClosedSessionRefusesAnyFurtherTransition(callable $first, callable $again): void
    {
        $session = self::started();
        $first($session, new DateTimeImmutable('2026-08-10T09:30:00+02:00'));
        $endedAt = $session->endedAt();
        $status = $session->status();

        try {
            $again($session, new DateTimeImmutable('2026-08-10T10:00:00+02:00'));
            self::fail('Une séance close ne peut plus changer d\'état.');
        } catch (SessionNotActive $error) {
            self::assertSame('session-not-active', $error->type());
            self::assertSame($status->value, $error->context()['sessionStatus']);
        }

        // Le refus ne laisse rien derrière lui : ni date de fin réécrite, ni statut modifié.
        self::assertSame($status, $session->status());
        self::assertEquals($endedAt, $session->endedAt());
    }

    /**
     * @return iterable<string, array{callable, callable}>
     */
    public static function closedTwice(): iterable
    {
        $complete = static fn (TrainingSession $s, DateTimeImmutable $at) => $s->complete($at);
        $abandon = static fn (TrainingSession $s, DateTimeImmutable $at) => $s->abandon($at);

        yield 'complétée puis complétée' => [$complete, $complete];
        yield 'complétée puis abandonnée' => [$complete, $abandon];
        yield 'abandonnée puis complétée' => [$abandon, $complete];
        yield 'abandonnée puis abandonnée' => [$abandon, $abandon];
    }

    public function testTheIdIsSortableByCreationOrder(): void
    {
        // C'est la raison du choix de l'UUID v7 : la pagination par curseur de
        // l'historique s'appuie dessus, sans colonne d'ordre supplémentaire.
        $first = self::started()->id();
        $second = self::started()->id();

        self::assertLessThan(0, strcmp($first->toRfc4122(), $second->toRfc4122()));
    }

    private static function started(): TrainingSession
    {
        return TrainingSession::start(
            Uuid::v7(),
            Discipline::Running,
            new DateTimeImmutable(self::START),
        );
    }
}
