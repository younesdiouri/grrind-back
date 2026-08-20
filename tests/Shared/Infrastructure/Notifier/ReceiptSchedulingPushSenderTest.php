<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Notifier;

use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRejection;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushSender;
use App\Shared\Application\PushTicket;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Doctrine\PendingPushReceiptRepository;
use App\Shared\Infrastructure\Notifier\ReceiptSchedulingPushSender;
use App\Tests\Support\ApiTestCase;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Le #131, côté envoi : un ticket accepté avec un identifiant se retrouve en base, prêt pour
 * {@see \App\Shared\Infrastructure\Notifier\CheckExpoPushReceiptsHandler} — et rien d'autre.
 * {@see \App\Shared\Infrastructure\Notifier\ExpoPushSender} reste testable sans conteneur ni
 * réseau (voir `ExpoPushSenderTest`) précisément parce que cette responsabilité vit ici, dans
 * le décorateur, pas dans le sender lui-même — voir son docblock.
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
