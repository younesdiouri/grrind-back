<?php

declare(strict_types=1);

namespace App\Tests\Training\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\SessionSource;
use App\Shared\Domain\Activity\TrustLevel;
use App\Training\Domain\Exception\WorkoutNotActive;
use App\Training\Domain\Exception\WorkoutTooShort;
use App\Training\Domain\SessionStatus;
use App\Training\Domain\Workout;
use App\Training\Domain\WorkoutRules;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le domaine se teste sans base, sans conteneur et sans horloge réelle : c'est la
 * contrepartie du `$now` passé en paramètre à chaque transition.
 */
final class WorkoutTest extends TestCase
{
    private const string START = '2026-08-10T09:00:00+02:00';

    public function testStartsActiveOnTheServerClock(): void
    {
        $now = new DateTimeImmutable(self::START);
        $session = Workout::start(Uuid::v7(), Discipline::Running, $now);

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
        $session->complete(new DateTimeImmutable('2026-08-10T09:45:30+02:00'), self::rules());

        self::assertSame(SessionStatus::Completed, $session->status());
        self::assertSame(2730, $session->durationSeconds());
        self::assertEquals(new DateTimeImmutable('2026-08-10T09:45:30+02:00'), $session->endedAt());
    }

    public function testAbandoningClosesTheSessionWithoutErasingIt(): void
    {
        $session = self::started();
        $session->abandon(new DateTimeImmutable('2026-08-10T09:02:00+02:00'), self::rules());

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
        $session->complete(new DateTimeImmutable('2026-08-10T07:30:00+00:00'), self::rules());

        self::assertSame(1800, $session->durationSeconds());
    }

    /**
     * Par l'abandon, seule voie sans plancher : c'est la seule façon d'observer une
     * durée nulle, et le point à vérifier est qu'elle ne devienne jamais négative.
     */
    public function testAClockGoingBackwardsCannotProduceANegativeDuration(): void
    {
        $session = self::started();
        $session->abandon(new DateTimeImmutable('2026-08-10T08:59:00+02:00'), self::rules());

        self::assertSame(0, $session->durationSeconds());
    }

    /**
     * Sous le plancher, la clôture est refusée et la séance reste intacte : le joueur
     * continue ou renonce, mais rien n'est décidé à sa place.
     */
    public function testACompletionUnderTheFloorChangesNothing(): void
    {
        $session = self::started();

        try {
            $session->complete(new DateTimeImmutable('2026-08-10T09:04:00+02:00'), self::rules());
            self::fail('Une séance sous le plancher ne peut pas être clôturée.');
        } catch (WorkoutTooShort $error) {
            self::assertSame('session-too-short', $error->type());
            self::assertSame(240, $error->context()['elapsedSeconds']);
            self::assertSame(60, $error->context()['remainingSeconds']);
        }

        self::assertSame(SessionStatus::Active, $session->status());
        self::assertNull($session->endedAt());
        self::assertNull($session->durationSeconds());
    }

    public function testTheDurationIsClippedAtTheCeilingRatherThanRejected(): void
    {
        $session = self::started();
        $session->complete(new DateTimeImmutable('2026-08-11T09:00:00+02:00'), self::rules());

        self::assertSame(SessionStatus::Completed, $session->status());
        // Vingt-quatre heures de chronomètre oublié, quatre heures créditées — et la
        // date de fin reste celle du serveur : c'est la durée retenue qui est écrêtée.
        self::assertSame(14400, $session->durationSeconds());
        self::assertEquals(new DateTimeImmutable('2026-08-11T09:00:00+02:00'), $session->endedAt());
    }

    public function testOnlyASessionAboveTheFloorCountsTowardTheCooldown(): void
    {
        $short = self::started();
        $short->abandon(new DateTimeImmutable('2026-08-10T09:01:00+02:00'), self::rules());

        $real = self::started();
        $real->abandon(new DateTimeImmutable('2026-08-10T09:30:00+02:00'), self::rules());

        self::assertFalse($short->countsTowardCooldown(self::rules()));
        self::assertTrue($real->countsTowardCooldown(self::rules()));
        // Une séance en cours n'a pas de durée, donc rien à compter.
        self::assertFalse(self::started()->countsTowardCooldown(self::rules()));
    }

    /**
     * @param callable(Workout, DateTimeImmutable): void $first
     * @param callable(Workout, DateTimeImmutable): void $again
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
        } catch (WorkoutNotActive $error) {
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
        $complete = static fn (Workout $s, DateTimeImmutable $at) => $s->complete($at, self::rules());
        $abandon = static fn (Workout $s, DateTimeImmutable $at) => $s->abandon($at, self::rules());

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

    /**
     * Les seuils de `config/game/v1/training.yaml`, réaffirmés en dur : un test qui
     * relit la configuration qu'il vérifie ne vérifie rien. Un rééquilibrage doit
     * faire échouer cette suite et forcer à relire ce qu'il change.
     */
    private static function rules(): WorkoutRules
    {
        return new WorkoutRules(300, 14400, 900);
    }

    private static function started(): Workout
    {
        return Workout::start(
            Uuid::v7(),
            Discipline::Running,
            new DateTimeImmutable(self::START),
        );
    }
}
