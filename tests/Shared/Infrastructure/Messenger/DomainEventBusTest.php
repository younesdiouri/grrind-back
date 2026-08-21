<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Messenger;

use App\Tests\Support\ApiTestCase;
use App\Tests\Support\Messaging\UnsubscribedDomainEvent;
use App\Tests\Support\Messaging\UnsubscribedMessage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Exception\NoHandlerForMessageException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * La règle que le #155 corrige : « aucun abonné » est un état légitime pour un
 * `DomainEvent`, pas une faute — voir le docblock de `messenger.yaml`. Les 631 tests qui
 * passaient avant ce ticket ne l'avaient jamais vue, parce que `WorkoutImportedSpy` et
 * `WorkoutCreditedSpy` (`tests/Support/Messaging/`) donnent un abonné aux deux seuls
 * `DomainEvent` qui existent aujourd'hui, dans le seul environnement `test`. Cette
 * suite prouve la règle sur un événement qui n'en a délibérément aucun.
 *
 * {@see self::testADomainEventWithoutASubscriberIsConsumedWithoutFailing()} est le test
 * qui compte : il échouerait si `allow_no_handlers` disparaissait d'`event.bus`, ou si un
 * point d'injection glissait de `event.bus` vers `command.bus`.
 *
 * {@see self::testACommandWithoutAHandlerStillFails()} tient l'autre bord : `command.bus`
 * garde sa sévérité, pour qu'une commande dont le handler a disparu continue d'exploser
 * plutôt que de passer en silence.
 */
final class DomainEventBusTest extends ApiTestCase
{
    public function testADomainEventWithoutASubscriberIsConsumedWithoutFailing(): void
    {
        self::assertSame(0, $this->outboxSize());

        // Routé vers `outbox` du seul fait qu'il implémente `DomainEvent` — voir la règle
        // sur l'interface dans `messenger.yaml`. Dispatché sur `event.bus` explicitement,
        // par le service de test aliasé dans `config/services.yaml`, plutôt que par un
        // point d'injection applicatif qui pourrait changer de bus au gré d'un refactor.
        $this->eventBus()->dispatch(new UnsubscribedDomainEvent(new DateTimeImmutable()));

        self::assertSame(1, $this->outboxSize());
        self::assertSame(0, $this->failedSize());

        $this->consumeTheOutbox();

        // Consommé sans lever, et surtout sans finir dans `failed` : c'est tout le point
        // du ticket. Avant le #155, ce même geste sortait vers `failed` après trois
        // tentatives — voir la sortie du worker citée dans le ticket.
        self::assertSame(0, $this->outboxSize());
        self::assertSame(0, $this->failedSize());
    }

    /**
     * `command.bus` reste le bus par défaut, et il garde sa sévérité : un
     * `MessageBusInterface` injecté sans `#[Target]` — comme ceux de
     * `GuildActivityNotifier`, `ReceiptSchedulingPushSender` et
     * `CheckExpoPushReceiptsHandler` — doit continuer de tomber dessus, pas sur
     * `event.bus`. `UnsubscribedMessage` n'est routée vers aucun transport : la
     * vérification a lieu en synchrone, dans ce process, sans worker.
     */
    public function testACommandWithoutAHandlerStillFails(): void
    {
        $this->expectException(NoHandlerForMessageException::class);

        $this->commandBus()->dispatch(new UnsubscribedMessage());
    }

    private function eventBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('messenger.test.event_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    private function commandBus(): MessageBusInterface
    {
        $bus = self::getContainer()->get('messenger.default_bus');
        self::assertInstanceOf(MessageBusInterface::class, $bus);

        return $bus;
    }

    /** Même geste que `GuildActivityNotifierTest::consumeTheOutbox()` : draine ce qui est déjà dû. */
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

    private function outboxSize(): int
    {
        return $this->queueSize('default');
    }

    private function failedSize(): int
    {
        return $this->queueSize('failed');
    }

    private function queueSize(string $queueName): int
    {
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM messenger_messages WHERE queue_name = :queue',
            ['queue' => $queueName],
        );
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
