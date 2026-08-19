<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Notifier;

use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRejection;
use App\Shared\Application\PushTargets;
use App\Shared\Infrastructure\Notifier\ExpoPushSender;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Texter;
use Symfony\Component\Notifier\TexterInterface;
use Symfony\Component\Notifier\Transport\NullTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Sans conteneur, sans réseau : ce que l'adapter garantit par construction. Le câblage
 * réel — `%env(EXPO_DSN)%`, forcé à `null://null` en environnement `test` — se prouve à
 * part, dans {@see PushSenderWiringTest}, comme `PushTargetsTest` le fait pour son
 * voisin.
 */
final class ExpoPushSenderTest extends TestCase
{
    public function testEachDeviceGetsItsOwnTicketInPushTargetsOrder(): void
    {
        $bob = Uuid::v7();
        $sender = new ExpoPushSender(new Texter(new NullTransport()), self::targetsOf($bob, ['token-a', 'token-b']), new NullLogger());

        $tickets = $sender->send($bob, self::notification());

        self::assertCount(2, $tickets);
        self::assertSame('token-a', $tickets[0]->pushToken);
        self::assertTrue($tickets[0]->accepted);
        self::assertSame('token-b', $tickets[1]->pushToken);
        self::assertTrue($tickets[1]->accepted);
    }

    public function testAPlayerWithNoDeviceGetsNoTicket(): void
    {
        $bob = Uuid::v7();
        $sender = new ExpoPushSender(new Texter(new NullTransport()), self::targetsOf($bob, []), new NullLogger());

        self::assertSame([], $sender->send($bob, self::notification()));
    }

    /**
     * Le cas nominal de dev/test : `NullTransport` ne part jamais sur le réseau et ne
     * fabrique aucun identifiant Expo — un ticket accepté sans `ticketId` est la preuve
     * que l'envoi a bien traversé l'adapter sans qu'un appel HTTP ait eu lieu.
     */
    public function testTheNullTransportProducesAnAcceptedTicketWithoutAnId(): void
    {
        $bob = Uuid::v7();
        $sender = new ExpoPushSender(new Texter(new NullTransport()), self::targetsOf($bob, ['token']), new NullLogger());

        [$ticket] = $sender->send($bob, self::notification());

        self::assertTrue($ticket->accepted);
        self::assertNull($ticket->ticketId);
    }

    /**
     * Un jeton mort ne doit pas priver les autres appareils du joueur de la
     * notification — la même tolérance que l'import face à un workout écarté.
     */
    public function testARejectedTokenDoesNotStopTheOthers(): void
    {
        $bob = Uuid::v7();
        $sender = new ExpoPushSender(self::texterRejecting('dead-token', self::bridgeMessage('DeviceNotRegistered')), self::targetsOf($bob, ['dead-token', 'live-token']), new NullLogger());

        [$dead, $live] = $sender->send($bob, self::notification());

        self::assertSame('dead-token', $dead->pushToken);
        self::assertFalse($dead->accepted);
        self::assertNull($dead->ticketId);

        self::assertSame('live-token', $live->pushToken);
        self::assertTrue($live->accepted);
    }

    /**
     * L'extraction que {@see PushRejection} documente : le code n'est nulle part ailleurs
     * que dans ce message formaté par `ExpoTransport::doSend()`, reconstitué ici tel
     * quel — pas une approximation, le vrai format que le bundle produit.
     */
    public function testARealBridgeMessageResolvesToItsPushRejection(): void
    {
        $bob = Uuid::v7();
        $bridgeMessage = self::bridgeMessage('DeviceNotRegistered');
        $sender = new ExpoPushSender(self::texterRejecting('dead-token', $bridgeMessage), self::targetsOf($bob, ['dead-token']), new NullLogger());

        [$ticket] = $sender->send($bob, self::notification());

        self::assertSame(PushRejection::DeviceNotRegistered, $ticket->rejection);
        self::assertSame($bridgeMessage, $ticket->rawReason);
    }

    /**
     * Tout ce qui ne matche pas le format connu — panne réseau, réponse non-200 sans
     * détail structuré, un futur format de bundle — rend `Unknown` plutôt que de lever :
     * un format inattendu ne doit jamais faire tomber l'envoi aux autres jetons.
     */
    public function testAnUnrecognizedMessageResolvesToUnknown(): void
    {
        $bob = Uuid::v7();
        $networkFailure = 'Could not reach the remote Expo server.';
        $sender = new ExpoPushSender(self::texterRejecting('dead-token', $networkFailure), self::targetsOf($bob, ['dead-token']), new NullLogger());

        [$ticket] = $sender->send($bob, self::notification());

        self::assertSame(PushRejection::Unknown, $ticket->rejection);
        self::assertSame($networkFailure, $ticket->rawReason);
    }

    /**
     * Le format exact que lève `ExpoTransport::doSend()` (`symfony/expo-notifier`) sur
     * un ticket refusé — voir le docblock de {@see PushRejection}. Le message côté Expo
     * ($message) n'importe pas ici, seul le code entre parenthèses en fin de chaîne compte.
     */
    private static function bridgeMessage(string $expoCode): string
    {
        return \sprintf('Unable to post the Expo message: "%s" (%s)', 'the token is not a valid Expo push token', $expoCode);
    }

    private static function notification(): PushNotification
    {
        return new PushNotification('Un coéquipier a rejoint', 'Dis bonjour à Bob.', 'guild-member-joined', 'guild-roster');
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

            public function of(Uuid $userId): array
            {
                return $userId->equals($this->forPlayer) ? $this->tokens : [];
            }
        };
    }

    /**
     * Un texter qui refuse un jeton précis et accepte tous les autres — assez pour
     * prouver la tolérance de l'adapter, sans reconstruire tout `ExpoTransport`.
     */
    private static function texterRejecting(string $rejectedToken, string $reason): TexterInterface
    {
        return new class($rejectedToken, $reason) implements TexterInterface {
            public function __construct(
                private readonly string $rejectedToken,
                private readonly string $reason,
            ) {
            }

            public function __toString(): string
            {
                return 'fake';
            }

            public function supports(MessageInterface $message): bool
            {
                return $message instanceof PushMessage;
            }

            public function send(MessageInterface $message): SentMessage
            {
                if ($message->getRecipientId() === $this->rejectedToken) {
                    throw new class($this->reason) extends RuntimeException implements TransportExceptionInterface {
                        public function getDebug(): string
                        {
                            return '';
                        }
                    };
                }

                return new SentMessage($message, 'fake');
            }
        };
    }
}
