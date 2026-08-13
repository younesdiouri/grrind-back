<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\TrustLevel;
use App\Shared\Domain\Activity\WorkoutSource;
use App\Shared\Domain\Event\WorkoutImported;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Messaging\WorkoutImportedSpy;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * `Training` ne connaît pas `Progression`, ni les classements du Lot 8 — Deptrac l'interdit,
 * et c'est ce qui empêchera le moteur de pourrir. L'import publie donc des faits, il
 * n'appelle personne.
 */
final class WorkoutImportedEventTest extends ApiTestCase
{
    use Workouts;

    protected function setUp(): void
    {
        parent::setUp();
        WorkoutImportedSpy::forget();
    }

    /**
     * **Un import publie N faits, jamais un agrégat.** Le classement compte des activités,
     * pas des synchronisations : un abonné qui recevrait « une synchronisation de trois »
     * devrait la défaire pour retrouver ce qui l'intéresse.
     */
    public function testABatchPublishesOneEventPerCreditedWorkout(): void
    {
        $bob = $this->openAccount();

        self::assertSame(0, $this->outboxSize());

        $response = $this->import($bob, [
            self::candidate(externalId: 'HK-1', startedAt: '2026-08-03T07:00:00+00:00'),
            self::candidate(externalId: 'HK-2', startedAt: '2026-08-04T07:00:00+00:00'),
            self::candidate(externalId: 'HK-3', startedAt: '2026-08-05T07:00:00+00:00'),
        ]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(3, $this->outboxSize());
    }

    /**
     * Le payload est autoportant : un abonné n'a aucune raison de rappeler `Training`.
     * `durationSeconds` en particulier est la durée **retenue** — la recalculer depuis les
     * deux bornes ignorerait l'écrêtage à venir (#91).
     */
    public function testTheEventCarriesTheWholeFact(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate(activityType: 'cycling', startedAt: '2026-08-05T07:00:00+00:00')]);

        $envelopes = iterator_to_array($this->outbox()->get());
        self::assertCount(1, $envelopes);

        $event = $envelopes[0]->getMessage();
        self::assertInstanceOf(WorkoutImported::class, $event);

        self::assertTrue($bob->id->equals($event->userId));
        self::assertSame(Discipline::Cycling, $event->discipline);
        self::assertSame(WorkoutSource::AppleHealth, $event->source);
        self::assertSame(TrustLevel::ProviderVerified, $event->trust);
        self::assertSame(1800, $event->durationSeconds);
        self::assertGreaterThan($event->startedAt, $event->endedAt);
    }

    /**
     * `occurredAt()` est l'instant du **sport**, pas celui de l'import. C'est le cas que
     * l'interface annonçait comme à venir : ici il a plus d'une semaine d'écart avec la
     * publication, et c'est le fait qui compte.
     */
    public function testTheEventIsDatedByTheSportAndNotByTheImport(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate(startedAt: '2026-08-01T06:30:00+00:00')]);

        $envelopes = iterator_to_array($this->outbox()->get());
        $event = $envelopes[0]->getMessage();
        self::assertInstanceOf(WorkoutImported::class, $event);

        self::assertSame('2026-08-01T06:30:00+00:00', $event->occurredAt()->format(DateTimeInterface::ATOM));
        self::assertEquals($event->startedAt, $event->occurredAt());
    }

    /**
     * Un workout écarté n'apprend rien à personne : il n'y a pas de fait à publier.
     */
    public function testASkippedWorkoutPublishesNothing(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [self::candidate(activityType: 'curling')]);

        self::assertSame(0, $this->outboxSize());
    }

    /**
     * Le « fini quand » du ticket : un module tiers réagit sans qu'aucune ligne de
     * `Training` ne le mentionne. Le spion n'est ni déclaré ni référencé nulle part — il
     * porte un `#[AsMessageHandler]` et un type-hint sur l'événement de `Shared`.
     */
    public function testAThirdPartyModuleReactsWithoutBeingNamedAnywhere(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate()]);

        self::assertCount(0, WorkoutImportedSpy::$received, 'L\'outbox est asynchrone : rien n\'est traité avant le worker.');

        $this->consumeTheOutbox();

        self::assertCount(1, WorkoutImportedSpy::$received);
        self::assertSame(0, $this->outboxSize());
    }

    /**
     * Le vrai worker, celui du `compose.yaml`, sur un message et pas un de plus : c'est la
     * seule façon de prouver que le routage va bien de l'événement à un abonné qui ne l'a
     * jamais déclaré.
     */
    private function consumeTheOutbox(): void
    {
        $application = new Application(self::bootedKernel());
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('messenger:consume'));
        $status = $tester->execute([
            'receivers' => ['outbox'],
            '--limit' => 1,
            '--time-limit' => 10,
        ]);

        self::assertSame(0, $status, $tester->getDisplay());
    }

    private static function bootedKernel(): KernelInterface
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        return $kernel;
    }

    private function outbox(): TransportInterface
    {
        $transport = self::getContainer()->get('messenger.transport.outbox');
        self::assertInstanceOf(TransportInterface::class, $transport);

        return $transport;
    }

    /**
     * Compté en base et non par `TransportInterface::get()`, qui ne rend qu'un message à la
     * fois : c'est le nombre de faits en attente qu'on vérifie, pas ce qu'un worker
     * recevrait au prochain tour.
     */
    private function outboxSize(): int
    {
        $pending = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => 'default'],
        );

        self::assertIsNumeric($pending);

        return (int) $pending;
    }

    /**
     * @param list<array<string, mixed>> $workouts
     */
    private function import(Account $account, array $workouts): Response
    {
        return $this->post(
            '/api/workouts/import',
            ['workouts' => $workouts],
            $account->headers + ['Idempotency-Key' => 'import-du-jour'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function candidate(
        string $externalId = 'HK-001',
        string $activityType = 'running',
        string $startedAt = '2026-08-05T07:00:00+00:00',
    ): array {
        return [
            'externalId' => $externalId,
            'source' => 'APPLE_HEALTH',
            'activityType' => $activityType,
            'startedAt' => $startedAt,
            'endedAt' => new DateTimeImmutable($startedAt)->modify('+1800 seconds')->format(DateTimeInterface::ATOM),
        ];
    }
}
