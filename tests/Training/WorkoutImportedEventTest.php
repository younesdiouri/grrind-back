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
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

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
     *
     * Lu par le spion plutôt que par `TransportInterface::get()` : depuis `WorkoutCredited`
     * (#128), le premier message de la file n'est plus forcément celui-ci — `Progression`
     * publie le sien avant que `Training` ne publie celui-là. Consommer et filtrer par type
     * est la seule lecture qui ne dépend pas de l'ordre.
     */
    public function testTheEventCarriesTheWholeFact(): void
    {
        $bob = $this->openAccount();
        $this->import($bob, [self::candidate(activityType: 'cycling', startedAt: '2026-08-05T07:00:00+00:00')]);

        $this->consumeTheOutbox();
        self::assertCount(1, WorkoutImportedSpy::$received);

        $event = WorkoutImportedSpy::$received[0];

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

        $this->consumeTheOutbox();
        $event = WorkoutImportedSpy::$received[0];

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
     * Le vrai worker, celui du `compose.yaml`, sur la file entière et pas un message de
     * moins : c'est la seule façon de prouver que le routage va bien de l'événement à un
     * abonné qui ne l'a jamais déclaré.
     *
     * La limite se lit en base plutôt qu'en dur : depuis `WorkoutCredited` (#128), un
     * workout crédité publie deux faits, et une limite figée à 1 laisserait la moitié de la
     * file derrière elle.
     */
    private function consumeTheOutbox(): void
    {
        $pending = $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
        self::assertIsNumeric($pending);

        $application = new Application(self::bootedKernel());
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find('messenger:consume'));
        $status = $tester->execute([
            'receivers' => ['outbox'],
            '--limit' => max(1, (int) $pending),
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

    /**
     * Compté en base et non par `TransportInterface::get()`, qui ne rend qu'un message à la
     * fois : c'est le nombre de faits en attente qu'on vérifie, pas ce qu'un worker
     * recevrait au prochain tour.
     *
     * Filtré sur le type, et décodé plutôt que lu dans `headers` : le sérialiseur du projet
     * (`PhpSerializer`, sous une signature) n'y écrit pas de `type` — la classe n'est
     * connue qu'une fois le corps décodé. Filtrer est nécessaire depuis `WorkoutCredited`
     * (#128) : l'outbox porte deux faits par workout crédité, et cette suite ne parle que
     * de celui qui est son sujet.
     */
    private function outboxSize(): int
    {
        return \count($this->pendingMessagesOfType(WorkoutImported::class));
    }

    /**
     * @return list<object>
     */
    private function pendingMessagesOfType(string $class): array
    {
        $serializer = self::getContainer()->get(SerializerInterface::class);
        self::assertInstanceOf(SerializerInterface::class, $serializer);

        // `headers` n'est pas relu : le `PhpSerializer` du projet ne s'en sert pas pour
        // décoder — la classe voyage dans le corps sérialisé — et une file vide ici
        // satisfait déjà la signature attendue par `decode()`.
        $rows = $this->connection()->fetchAllAssociative('SELECT body FROM messenger_messages');
        $messages = [];

        foreach ($rows as $row) {
            self::assertIsString($row['body']);
            $message = $serializer->decode(['body' => $row['body'], 'headers' => []])->getMessage();

            if ($message instanceof $class) {
                $messages[] = $message;
            }
        }

        return $messages;
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
