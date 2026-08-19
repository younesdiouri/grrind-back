<?php

declare(strict_types=1);

namespace App\Tests\Training;

use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Messaging\WorkoutCreditedSpy;
use App\Tests\Support\ProgrammableModifiers;
use App\Tests\Support\Workouts;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `WorkoutCredited` (#128) : ce que la séance a rapporté, publié par `Progression` — pas
 * par `Training`, qui ne sait que déclencher le crédit par le port `SessionRewards`. C'est
 * le fait symétrique de {@see WorkoutImportedEventTest} : celui-là dit qu'un workout a eu
 * lieu, celui-ci dit ce qu'il a rapporté, et seulement quand il a rapporté quelque chose.
 */
final class WorkoutCreditedEventTest extends ApiTestCase
{
    use Workouts;

    protected function setUp(): void
    {
        parent::setUp();
        WorkoutCreditedSpy::forget();
    }

    /**
     * Le payload est autoportant sur ce qu'un abonné asynchrone a besoin de savoir pour
     * annoncer un gain : qui, combien, sous quel équilibrage, et si un niveau est tombé.
     */
    public function testTheEventCarriesWhatTheSessionEarned(): void
    {
        $bob = $this->openAccount();
        $response = $this->import($bob, [self::candidate(durationSeconds: 1800)]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $body = self::decode($response);
        self::assertIsArray($body['imported']);
        $reward = $body['imported'][0];
        self::assertIsArray($reward);
        self::assertIsArray($reward['session']);
        $workoutId = $reward['session']['id'];
        self::assertIsString($workoutId);
        $level = $reward['level'];
        self::assertIsArray($level);

        $this->consumeTheOutbox();
        self::assertCount(1, WorkoutCreditedSpy::$received);
        $event = WorkoutCreditedSpy::$received[0];

        self::assertSame($workoutId, $event->workoutId->toRfc4122());
        self::assertTrue($bob->id->equals($event->userId));
        self::assertSame(30, $event->xpGranted, 'Une demi-heure, une minute pour un point, sans modificateur ni rendement décroissant.');
        self::assertNotSame('', $event->rulesetVersion);

        // Le même franchissement que la réponse HTTP : un abonné n'a rien à recalculer.
        self::assertSame($level['before'], $event->levelBefore);
        self::assertSame($level['after'], $event->levelAfter);
    }

    /**
     * `occurredAt()` est daté par le **sport**, comme `WorkoutImported` : les deux faits
     * décrivent la même séance, ils partagent son instant plutôt que celui de la
     * publication.
     */
    public function testTheEventIsDatedByTheSport(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate(startedAt: '2026-08-01T06:30:00+00:00')]);

        $this->consumeTheOutbox();
        $event = WorkoutCreditedSpy::$received[0];

        self::assertSame('2026-08-01T06:30:00+00:00', $event->occurredAt()->format(DateTimeInterface::ATOM));
    }

    /**
     * **Le cas que le ticket met en garde.** Un workout hors fenêtre est écrit sans être
     * crédité : `SessionRewards::creditFor()` n'est jamais appelée pour lui, donc
     * `Progression` n'a rien à annoncer. Publier `WorkoutCredited` ici annoncerait un gain
     * qui n'a pas eu lieu.
     */
    public function testAnOutOfWindowWorkoutPublishesNoCreditedEvent(): void
    {
        $bob = $this->openAccount();

        $response = $this->import($bob, [self::candidate(daysAgo: 200)]);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(0, $this->snapshotTotalOf($bob), 'Hors fenêtre : rien n\'est crédité.');

        $this->consumeTheOutbox();
        self::assertCount(0, WorkoutCreditedSpy::$received);
    }

    /**
     * Un lot mêlant un crédité et un hors fenêtre : un seul événement, pour celui qui a
     * réellement rapporté quelque chose — jamais un agrégat par synchronisation.
     */
    public function testOnlyTheCreditedWorkoutOfTheBatchPublishesTheEvent(): void
    {
        $bob = $this->openAccount();

        $this->import($bob, [
            self::candidate(externalId: 'HK-RECENT', daysAgo: 3),
            self::candidate(externalId: 'HK-VIEUX', daysAgo: 200),
        ]);

        $this->consumeTheOutbox();
        self::assertCount(1, WorkoutCreditedSpy::$received);
    }

    /**
     * L'atomicité de l'outbox : une panne au milieu du lot défait le crédit du premier
     * workout, donc son annonce avec — même démonstration que pour `WorkoutImported` dans
     * {@see ImportTransactionTest::testAFailureMidBatchLeavesNeitherWorkoutNorXpNorEvent}.
     */
    public function testAFailureMidBatchLeavesNoCreditedEvent(): void
    {
        $bob = $this->openAccount();
        ProgrammableModifiers::failAfter(1);

        $response = $this->import($bob, [
            self::candidate(externalId: 'HK-AVANT', startedAt: '2026-08-03T07:00:00+00:00'),
            self::candidate(externalId: 'HK-APRES', startedAt: '2026-08-04T07:00:00+00:00'),
        ]);

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertSame(0, $this->outboxSize(), 'Le rollback porte sur tout : ni workout, ni crédit, ni annonce.');
    }

    /**
     * Le vrai worker, comme dans {@see WorkoutImportedEventTest} : sur la file entière, il
     * draine tout ce que le lot a publié — `WorkoutImported` et `WorkoutCredited` ensemble —
     * donc les deux spies se partagent la file sans se marcher dessus.
     *
     * `--limit` refuse zéro : quand le lot n'a rien crédité, la file est vide et il n'y a
     * rien à consommer, donc on ne lance même pas la commande.
     */
    private function consumeTheOutbox(): void
    {
        $pending = $this->outboxSize();

        if (0 === $pending) {
            return;
        }

        $application = new Application(self::bootedKernel());
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('messenger:consume'));
        $status = $tester->execute([
            'receivers' => ['outbox'],
            '--limit' => $pending,
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

    private function outboxSize(): int
    {
        $pending = $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
        self::assertIsNumeric($pending);

        return (int) $pending;
    }

    /**
     * @param list<array<string, mixed>> $workouts
     */
    private function import(Account $account, array $workouts, string $key = 'import-du-jour'): Response
    {
        return $this->post(
            '/api/workouts/import',
            ['workouts' => $workouts],
            $account->headers + ['Idempotency-Key' => $key],
        );
    }

    private function snapshotTotalOf(Account $account): int
    {
        $value = $this->connection()->fetchOne(
            'SELECT COALESCE(MAX(total_xp), 0) FROM progression_snapshot WHERE user_id = :id',
            ['id' => $account->id->toRfc4122()],
        );
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function candidate(
        string $externalId = 'HK-001',
        ?string $startedAt = null,
        ?int $daysAgo = null,
        int $durationSeconds = 1800,
    ): array {
        $start = null !== $daysAgo
            ? new DateTimeImmutable(\sprintf('-%d days', $daysAgo))->setTime(7, 0)
            : new DateTimeImmutable($startedAt ?? '2026-08-05T07:00:00+00:00');

        return [
            'externalId' => $externalId,
            'source' => 'APPLE_HEALTH',
            'activityType' => 'running',
            'startedAt' => $start->format(DateTimeInterface::ATOM),
            'endedAt' => $start->modify(\sprintf('+%d seconds', $durationSeconds))->format(DateTimeInterface::ATOM),
        ];
    }
}
