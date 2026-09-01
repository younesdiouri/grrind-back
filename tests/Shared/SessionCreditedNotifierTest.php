<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Application\AnnounceSessionCredit;
use App\Shared\Domain\Activity\Discipline;
use App\Shared\Infrastructure\Doctrine\PendingSessionCreditRepository;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Messaging\WorkoutCreditedSpy;
use App\Tests\Support\SpyingPushSender;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * #255 garde le contrat Messenger de `AnnounceSessionCredit` pendant le rollout, sans plus
 * produire de nouvelle annonce auteur. Ces tests prouvent les deux sens : une nouvelle séance
 * ne crée rien, tandis qu'un message historique reste consommable et referme son tombeau.
 */
final class SessionCreditedNotifierTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkoutCreditedSpy::forget();
        SpyingPushSender::forget();
    }

    public function testAFreshCreditedWorkoutCreatesNoSessionCreditedWindowMessagePushOrAttempt(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');

        $response = $this->import($author, [self::freshCandidate()]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $this->consumeTheOutbox();

        self::assertCount(1, WorkoutCreditedSpy::$received, 'La séance doit rester créditée : seul son push auteur est retiré.');
        self::assertNull($this->pending()->find($author->id), 'Une séance nouvelle ne doit plus ouvrir shared_pending_session_credit.');
        self::assertSame(0, $this->queuedAnnouncements(), 'Une séance nouvelle ne doit plus créer AnnounceSessionCredit.');
        self::assertCount(0, SpyingPushSender::$sent, 'Aucun push SESSION_CREDITED ne doit partir pour l\'auteur.');
        self::assertSame(0, $this->sessionCreditedAttempts(), 'Sans annonce auteur, aucune NotificationAttempt SESSION_CREDITED ne doit être réservée.');
    }

    public function testAnOldSerializedAnnouncementClosesItsWindowWithoutPushOrAttempt(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');
        $windowId = $this->openLegacyWindow($author);

        // Le passage par le bus puis le transport Doctrine exerce le message sérialisé, son
        // routage et son handler plutôt qu'un appel direct propre au test.
        $this->bus()->dispatch(new AnnounceSessionCredit($author->id, $windowId));
        $this->consumeTheOutbox();

        self::assertNull($this->pending()->find($author->id), 'Le tombeau doit drainer la fenêtre historique.');
        self::assertCount(0, SpyingPushSender::$sent, 'Le drainage d\'un message historique ne doit appeler aucun PushSender.');
        self::assertSame(0, $this->sessionCreditedAttempts(), 'Le drainage d\'un message historique ne doit réserver aucune NotificationAttempt.');

        // Messenger livre au moins une fois : le rejeu après fermeture reste un no-op.
        $this->bus()->dispatch(new AnnounceSessionCredit($author->id, $windowId));
        $this->consumeTheOutbox();

        self::assertNull($this->pending()->find($author->id));
        self::assertCount(0, SpyingPushSender::$sent);
        self::assertSame(0, $this->sessionCreditedAttempts());
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

    /**
     * @return array<string, mixed>
     */
    private static function freshCandidate(): array
    {
        $endedAt = new DateTimeImmutable('-5 minutes');
        $startedAt = $endedAt->modify('-1800 seconds');

        return [
            'externalId' => 'HK-001',
            'source' => 'APPLE_HEALTH',
            'activityType' => 'running',
            'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
            'endedAt' => $endedAt->format(DateTimeInterface::ATOM),
        ];
    }

    private function openLegacyWindow(Account $author): Uuid
    {
        $windowId = $this->pending()->recordSession(
            $author->id,
            Discipline::Running,
            1800,
            18,
            1,
            1,
            new DateTimeImmutable(),
        );

        self::assertInstanceOf(Uuid::class, $windowId);

        return $windowId;
    }

    private function pending(): PendingSessionCreditRepository
    {
        $repository = self::getContainer()->get(PendingSessionCreditRepository::class);
        self::assertInstanceOf(PendingSessionCreditRepository::class, $repository);

        return $repository;
    }

    private function bus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function consumeTheOutbox(): void
    {
        while (($pending = $this->outboxSize()) > 0) {
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
    }

    private function queuedAnnouncements(): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE body LIKE '%AnnounceSessionCredit%'");
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function sessionCreditedAttempts(): int
    {
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM shared_notification_attempt WHERE category = :category',
            ['category' => 'SESSION_CREDITED'],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function outboxSize(): int
    {
        $pending = $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE available_at <= NOW()');
        self::assertIsNumeric($pending);

        return (int) $pending;
    }

    private static function bootedKernel(): KernelInterface
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        return $kernel;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
