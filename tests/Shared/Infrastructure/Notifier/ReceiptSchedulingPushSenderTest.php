<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Notifier;

use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRejection;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushSender;
use App\Shared\Application\PushTargets;
use App\Shared\Application\PushTicket;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\PendingPushReceiptRepository;
use App\Shared\Infrastructure\Notifier\ExpoPushSender;
use App\Shared\Infrastructure\Notifier\ReceiptSchedulingPushSender;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\SpyingDeadPushTokens;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Bridge\Expo\ExpoTransport;
use Symfony\Component\Notifier\Transport\Transports;
use Symfony\Component\Uid\Uuid;

/**
 * Le #131, côté envoi : un ticket accepté avec un identifiant se retrouve en base, prêt pour
 * {@see \App\Shared\Infrastructure\Notifier\CheckExpoPushReceiptsHandler} — et rien d'autre.
 * {@see ExpoPushSender} reste testable sans conteneur ni réseau (voir `ExpoPushSenderTest`)
 * précisément parce que cette responsabilité vit ici, dans le décorateur, pas dans le sender
 * lui-même — voir son docblock.
 *
 * **Le #150, la moitié qu'`ExpoPushSenderTest` ne peut pas prouver seul.** Un `ticketId` qui
 * ressort du vrai bridge Expo ne sert à rien s'il ne traverse pas jusqu'à
 * `PendingPushReceiptRepository` — c'est ici, avec le vrai conteneur, que ça se vérifie :
 * {@see self::testARealBridgeTicketIsRecordedForItsReceipt()} construit un {@see ExpoPushSender}
 * avec un vrai `ExpoTransport` enveloppé dans un `Transports` (`MockHttpClient`, jamais un
 * double de `TexterInterface`), le décore comme la production le fait, et prouve que la
 * ligne existe. Ce test prouve que le `ticketId` traverse le vrai bridge — pas que le
 * câblage est protégé contre un retour en arrière vers `TexterInterface`/`Texter` : c'est
 * le type `Transports` du constructeur d'`ExpoPushSender` qui tient cette garantie, à la
 * compilation du conteneur.
 */
final class ReceiptSchedulingPushSenderTest extends ApiTestCase
{
    public function testAnAcceptedTicketWithAnIdIsRecordedAndACheckIsScheduled(): void
    {
        $bob = Uuid::v7();
        $decorator = $this->decorate(self::innerSender([PushTicket::accepted('token-a', 'ticket-a')]));

        $decorator->send($bob, self::notification());

        self::assertSame(1, $this->pendingReceiptCount());
        self::assertSame(1, $this->scheduledChecksCount());
    }

    /**
     * Le cas nominal de dev/test, exactement comme pour `ExpoPushSender` lui-même : un ticket
     * accepté sans identifiant n'a rien à interroger plus tard.
     */
    public function testAnAcceptedTicketWithoutAnIdIsIgnored(): void
    {
        $bob = Uuid::v7();
        $decorator = $this->decorate(self::innerSender([PushTicket::accepted('token-a', null)]));

        $decorator->send($bob, self::notification());

        self::assertSame(0, $this->pendingReceiptCount());
        self::assertSame(0, $this->scheduledChecksCount());
    }

    public function testARejectedTicketIsIgnored(): void
    {
        $bob = Uuid::v7();
        $decorator = $this->decorate(self::innerSender([PushTicket::rejected('token-a', PushRejection::DeviceNotRegistered, 'raison')]));

        $decorator->send($bob, self::notification());

        self::assertSame(0, $this->pendingReceiptCount());
        self::assertSame(0, $this->scheduledChecksCount());
    }

    /** Un seul message différé, quel que soit le nombre d'appareils du joueur. */
    public function testSeveralAcceptedTicketsScheduleASingleCheck(): void
    {
        $bob = Uuid::v7();
        $decorator = $this->decorate(self::innerSender([
            PushTicket::accepted('token-a', 'ticket-a'),
            PushTicket::accepted('token-b', 'ticket-b'),
        ]));

        $decorator->send($bob, self::notification());

        self::assertSame(2, $this->pendingReceiptCount());
        self::assertSame(1, $this->scheduledChecksCount());
    }

    public function testReturnsWhatTheInnerSenderReturned(): void
    {
        $bob = Uuid::v7();
        $tickets = [PushTicket::accepted('token-a', 'ticket-a')];
        $decorator = $this->decorate(self::innerSender($tickets));

        self::assertSame($tickets, $decorator->send($bob, self::notification()));
    }

    /**
     * Le #150 : preuve, avec le vrai bridge Expo, que le `ticketId` sort de
     * `ExpoTransport::doSend()` jusqu'à `PendingPushReceiptRepository` — c'est le chemin
     * que `Texter::send()` cassait, en rendant toujours `null` dès qu'un bus Messenger lui
     * est injecté (voir le docblock d'`ExpoPushSender`). Rouge sur `main` avant #150 :
     * `ExpoPushSender` n'y accepte qu'un `TexterInterface`, et un `ExpoTransport` ne
     * l'implémente pas — un `TypeError`, pas une assertion qui échoue. Ce test prouve
     * que le `ticketId` traverse, pas que le câblage reste protégé : voir le docblock de
     * la classe.
     */
    public function testARealBridgeTicketIsRecordedForItsReceipt(): void
    {
        $bob = Uuid::v7();
        $mockClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'data' => ['status' => 'ok', 'id' => '01a02101-1fe1-719d-8525-f3774d798bd1'],
        ], \JSON_THROW_ON_ERROR)));
        $expoSender = new ExpoPushSender(new Transports(['expo' => new ExpoTransport(null, $mockClient)]), self::targetsOf($bob, ['token-a']), new SpyingDeadPushTokens(), new NullLogger());
        $decorator = $this->decorate($expoSender);

        $tickets = $decorator->send($bob, self::notification());

        self::assertTrue($tickets[0]->accepted);
        self::assertSame('01a02101-1fe1-719d-8525-f3774d798bd1', $tickets[0]->ticketId);
        self::assertSame(1, $this->pendingReceiptCount());
        self::assertSame(1, $this->scheduledChecksCount());
    }

    /**
     * @param list<PushTicket> $tickets
     */
    private static function innerSender(array $tickets): PushSender
    {
        return new class($tickets) implements PushSender {
            /** @param list<PushTicket> $tickets */
            public function __construct(private readonly array $tickets)
            {
            }

            public function send(Uuid $userId, PushNotification $notification): array
            {
                return $this->tickets;
            }
        };
    }

    private static function notification(): PushNotification
    {
        return new PushNotification('Titre', 'Corps', NotificationCategory::GuildActivity, 'grouping-key', new PushRoute(PushRouteType::PlayerProfile, Uuid::v7()));
    }

    /**
     * @param list<string> $tokens
     */
    private static function targetsOf(Uuid $forPlayer, array $tokens): PushTargets
    {
        return new class($forPlayer, $tokens) implements PushTargets {
            /** @param list<string> $tokens */
            public function __construct(
                private readonly Uuid $forPlayer,
                private readonly array $tokens,
            ) {
            }

            public function of(Uuid $userId, NotificationCategory $category): array
            {
                return $userId->equals($this->forPlayer) ? $this->tokens : [];
            }
        };
    }

    private function decorate(PushSender $inner): ReceiptSchedulingPushSender
    {
        $repository = self::getContainer()->get(PendingPushReceiptRepository::class);
        self::assertInstanceOf(PendingPushReceiptRepository::class, $repository);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);

        return new ReceiptSchedulingPushSender($inner, $repository, $bus, $clock);
    }

    private function pendingReceiptCount(): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM shared_pending_push_receipt');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function scheduledChecksCount(): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE body LIKE '%CheckExpoPushReceipts%'");
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function connection(): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
