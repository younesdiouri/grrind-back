<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Application\AnnounceSessionCredit;
use App\Shared\Application\AnnounceSessionCreditHandler;
use App\Shared\Domain\Notification\PendingSessionCredit;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\NotificationAttemptRepository;
use App\Shared\Infrastructure\Doctrine\PendingSessionCreditRepository;
use App\Tests\Support\Account;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\LocalHours;
use App\Tests\Support\Messaging\WorkoutCreditedSpy;
use App\Tests\Support\SpyingPushSender;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `SessionCreditedNotifier` (#252) : le joueur reçoit « Bien joué ! » pour sa propre séance
 * créditée, un seul push par import — jamais un par séance.
 *
 * {@see self::testABatchImportedAtOnceProducesExactlyOnePush()} est le test qui dit si le
 * ticket est fait, même rôle que son pendant côté guilde
 * ({@see \App\Tests\Community\GuildActivityNotifierTest}), dont ce fichier reprend la
 * mécanique de test presque à l'identique — même agrégation par fenêtre, même délai à
 * flusher explicitement. Ce qui diffère : un seul destinataire (l'auteur), et les heures
 * calmes qui ne s'appliquent pas ({@see self::testQuietHoursDoNotApplyToOwnSession()}) —
 * c'est la divergence assumée par le ticket, elle mérite son propre test.
 */
final class SessionCreditedNotifierTest extends ApiTestCase
{
    use LocalHours;

    protected function setUp(): void
    {
        parent::setUp();
        WorkoutCreditedSpy::forget();
        SpyingPushSender::forget();
    }

    /**
     * Le test du ticket : un joueur qui rentre après une absence synchronise plusieurs
     * séances d'un coup, toutes fraîches. Il ne reçoit qu'**un** push, pas un par séance —
     * trois séances créditées, jamais trois pushes.
     */
    public function testABatchImportedAtOnceProducesExactlyOnePush(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');

        $response = $this->import($author, [
            self::freshCandidate(externalId: 'HK-1', endedMinutesAgo: 110),
            self::freshCandidate(externalId: 'HK-2', endedMinutesAgo: 70),
            self::freshCandidate(externalId: 'HK-3', endedMinutesAgo: 30),
        ]);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $this->consumeTheOutbox();

        self::assertCount(3, WorkoutCreditedSpy::$received, 'Les trois séances doivent avoir été créditées pour que le test prouve quelque chose.');
        $totalXp = array_sum(array_map(static fn ($event) => $event->xpGranted, WorkoutCreditedSpy::$received));

        $this->flushPendingAnnouncement($author);

        self::assertCount(1, SpyingPushSender::$sent, 'Un push par import, pas un par séance : trois séances créditées ne doivent produire ni zéro ni trois envois.');

        $sent = SpyingPushSender::$sent[0];
        self::assertTrue($sent['recipientId']->equals($author->id), 'Le seul destinataire d\'une séance créditée est son propre auteur.');
        self::assertSame('Bien joué !', $sent['notification']->title);
        self::assertSame('SESSION_CREDITED', $sent['notification']->category->value);
        self::assertStringContainsString('3 séances', $sent['notification']->body);
        self::assertStringContainsString('+'.$totalXp.' XP', $sent['notification']->body);

        // Le tap mène au profil de l'auteur lui-même — v1, voir le docblock du handler.
        self::assertSame(PushRouteType::PlayerProfile, $sent['notification']->route->type);
        self::assertTrue($sent['notification']->route->targetId->equals($author->id));
    }

    /** Le second cas du même test : tout le lot est ancien, personne n'est notifié. */
    public function testABatchOfOldSessionsProducesNoPush(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');

        $this->import($author, [
            self::freshCandidate(externalId: 'HK-1', endedMinutesAgo: 200),
            self::freshCandidate(externalId: 'HK-2', endedMinutesAgo: 300),
        ]);

        $this->consumeTheOutbox();

        self::assertCount(2, WorkoutCreditedSpy::$received, 'Un workout ancien reste crédité : seul le push est retenu, pas l\'XP.');
        self::assertCount(0, SpyingPushSender::$sent);
    }

    /**
     * Une seule séance créditée porte le message détaillé — discipline, durée, XP — plutôt
     * que la forme agrégée réservée à plusieurs séances.
     */
    public function testASingleFreshSessionUsesTheDetailedMessage(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');

        $this->import($author, [self::freshCandidate(durationSeconds: 2700)]);

        $this->consumeTheOutbox();

        self::assertCount(1, WorkoutCreditedSpy::$received);
        $xp = WorkoutCreditedSpy::$received[0]->xpGranted;

        $this->flushPendingAnnouncement($author);

        self::assertCount(1, SpyingPushSender::$sent);
        self::assertStringContainsString('45 min de course', SpyingPushSender::$sent[0]['notification']->body);
        self::assertStringContainsString('+'.$xp.' XP', SpyingPushSender::$sent[0]['notification']->body);
    }

    /**
     * La divergence tranchée par le ticket : les heures calmes existent pour qu'on ne
     * réveille pas quelqu'un *d'autre*, jamais pour faire taire la récompense de sa propre
     * séance. Le compte est placé à 2h du matin locales — en plein cœur des heures calmes de
     * `notifications.yaml` (22h-8h) — et reçoit quand même son push.
     */
    public function testQuietHoursDoNotApplyToOwnSession(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');

        $response = $this->send('PATCH', '/api/me', ['timezone' => self::timezoneShiftingUtcHourTo(2)], $author->headers);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());

        $this->import($author, [self::freshCandidate()]);
        $this->consumeTheOutbox();
        $this->flushPendingAnnouncement($author);

        self::assertCount(1, SpyingPushSender::$sent, 'Sa propre séance ne doit jamais être retenue par les heures calmes, contrairement à une notification qui viendrait de quelqu\'un d\'autre.');
        self::assertTrue(SpyingPushSender::$sent[0]['recipientId']->equals($author->id));
    }

    /**
     * Le #134, réutilisé à l'identique : un rejeu du même message après un traitement déjà
     * complet ne renvoie rien — la fenêtre a été refermée par la première exécution.
     */
    public function testAReplayedAnnouncementAfterFullSuccessSendsNothingAgain(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');

        $this->import($author, [self::freshCandidate()]);
        $this->consumeTheOutbox();

        $windowId = $this->flushPendingAnnouncement($author);
        self::assertCount(1, SpyingPushSender::$sent, 'La première exécution doit avoir notifié l\'auteur pour que le rejeu démontre quelque chose.');

        $this->announce($author, $windowId);

        self::assertCount(1, SpyingPushSender::$sent, 'Le rejeu du même message ne doit ajouter aucun envoi : la fenêtre est déjà refermée.');
    }

    /**
     * Le scénario que le #134 cible vraiment côté guilde, réutilisé ici : une réservation
     * d'envoi déjà posée — comme si le push était parti avant une panne — empêche le rejeu
     * de renvoyer.
     */
    public function testAnAlreadyClaimedWindowIsNotResent(): void
    {
        $author = $this->openAccount('author@grrind.app', 'Author');

        $this->import($author, [self::freshCandidate()]);
        $this->consumeTheOutbox();

        $windowId = $this->currentWindowId($author);

        $attempts = self::getContainer()->get(NotificationAttemptRepository::class);
        self::assertInstanceOf(NotificationAttemptRepository::class, $attempts);
        self::assertTrue($attempts->claim($windowId, $author->id, NotificationCategory::SessionCredited, new DateTimeImmutable()), 'La réservation doit réussir la première fois — c\'est ce qui simule l\'envoi déjà parti avant la panne.');

        $this->announce($author, $windowId);

        self::assertCount(0, SpyingPushSender::$sent, 'Le destinataire déjà tracé ne doit pas recevoir de second envoi.');
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
    private static function freshCandidate(
        string $externalId = 'HK-001',
        int $endedMinutesAgo = 5,
        int $durationSeconds = 1800,
    ): array {
        $endedAt = new DateTimeImmutable(\sprintf('-%d minutes', $endedMinutesAgo));
        $startedAt = $endedAt->modify(\sprintf('-%d seconds', $durationSeconds));

        return [
            'externalId' => $externalId,
            'source' => 'APPLE_HEALTH',
            'activityType' => 'running',
            'startedAt' => $startedAt->format(DateTimeInterface::ATOM),
            'endedAt' => $endedAt->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Invoque `AnnounceSessionCreditHandler` directement, comme si le `DelayStamp` posé par
     * `SessionCreditedNotifier` s'était déjà écoulé — l'outbox ne le sert jamais avant ça.
     *
     * Rend le `windowId` traité, pour les tests d'idempotence qui ont besoin de le rejouer
     * explicitement — la fenêtre étant refermée en sortie de handler, on ne peut plus le
     * relire depuis la base après coup.
     */
    private function flushPendingAnnouncement(Account $author): Uuid
    {
        $windowId = $this->currentWindowId($author);
        $this->announce($author, $windowId);

        return $windowId;
    }

    /** Le `windowId` de la fenêtre actuellement ouverte pour ce joueur. */
    private function currentWindowId(Account $author): Uuid
    {
        $repository = self::getContainer()->get(PendingSessionCreditRepository::class);
        self::assertInstanceOf(PendingSessionCreditRepository::class, $repository);

        $pending = $repository->find($author->id);
        self::assertInstanceOf(PendingSessionCredit::class, $pending, 'Aucune fenêtre ouverte pour ce joueur : le test qui appelle ceci a-t-il bien crédité une séance fraîche avant ?');

        return $pending->windowId();
    }

    /** Rejoue `AnnounceSessionCreditHandler` pour un `(playerId, windowId)` précis, sans relire l'état courant — c'est le point du test de rejeu. */
    private function announce(Account $author, Uuid $windowId): void
    {
        $handler = self::getContainer()->get(AnnounceSessionCreditHandler::class);
        self::assertInstanceOf(AnnounceSessionCreditHandler::class, $handler);

        $handler(new AnnounceSessionCredit($author->id, $windowId));
    }

    /**
     * Draine l'outbox de tout ce qui est déjà dû — `AnnounceSessionCredit`, elle, porte
     * toujours un `DelayStamp` : cette boucle ne l'atteint jamais, voir
     * `flushPendingAnnouncement()`.
     */
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

    private static function bootedKernel(): KernelInterface
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);

        return $kernel;
    }

    /**
     * Seulement ce qui est **déjà dû** : un message retardé par un `DelayStamp` reste une
     * ligne de la table sans être un travail que `messenger:consume` sait servir
     * maintenant.
     */
    private function outboxSize(): int
    {
        $pending = $this->connection()->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE available_at <= NOW()');
        self::assertIsNumeric($pending);

        return (int) $pending;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
