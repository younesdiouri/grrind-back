<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Notifier;

use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRejection;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushTargets;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Shared\Infrastructure\Notifier\ExpoPushSender;
use App\Tests\Support\SpyingDeadPushTokens;
use App\Tests\Support\SpyingTexter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Notifier\Bridge\Expo\ExpoOptions;
use Symfony\Component\Notifier\Bridge\Expo\ExpoTransport;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\PushMessage;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\NullTransport;
use Symfony\Component\Notifier\Transport\TransportInterface;
use Symfony\Component\Notifier\Transport\Transports;
use Symfony\Component\Uid\Uuid;

/**
 * Sans conteneur, sans réseau réel : ce que l'adapter garantit par construction. Le
 * câblage réel — `%env(EXPO_DSN)%`, forcé à `null://null` en environnement `test` — se
 * prouve à part, dans {@see PushSenderWiringTest}, comme `PushTargetsTest` le fait pour
 * son voisin.
 *
 * Depuis le #150, `ExpoPushSender` consomme un `Transports` (le service
 * `texter.transports`), pas un `TexterInterface` — voir son docblock pour pourquoi, y
 * compris pourquoi ni `TransportInterface` ni `TexterInterface` ne suffisaient. Chaque
 * transport ci-dessous — `NullTransport`, un double `TransportInterface`, ou le vrai
 * `ExpoTransport` — s'enveloppe donc dans un `Transports` au point d'appel, exactement
 * ce que `texter.transports` est dans le conteneur. La plupart des tests passent
 * `NullTransport` ou un double directement, mais deux d'entre eux vont plus loin et
 * traversent le **vrai** `ExpoTransport` du bridge, avec `MockHttpClient` : c'est ce qui
 * prouve que le `ticketId` d'Expo ressort réellement, plutôt que de le supposer depuis
 * un double qui ne reconstruit pas le format de réponse du bridge — le câblage lui-même
 * (que `'@texter'` ne puisse plus être branché à la place) est garanti par le type
 * `Transports`, pas par ces tests.
 */
final class ExpoPushSenderTest extends TestCase
{
    public function testEachDeviceGetsItsOwnTicketInPushTargetsOrder(): void
    {
        $bob = Uuid::v7();
        $sender = new ExpoPushSender(new Transports(['expo' => new NullTransport()]), self::targetsOf($bob, ['token-a', 'token-b']), new SpyingDeadPushTokens(), new NullLogger());

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
        $sender = new ExpoPushSender(new Transports(['expo' => new NullTransport()]), self::targetsOf($bob, []), new SpyingDeadPushTokens(), new NullLogger());

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
        $sender = new ExpoPushSender(new Transports(['expo' => new NullTransport()]), self::targetsOf($bob, ['token']), new SpyingDeadPushTokens(), new NullLogger());

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
        $sender = new ExpoPushSender(new Transports(['expo' => self::transportRejecting('dead-token', self::bridgeMessage('DeviceNotRegistered'))]), self::targetsOf($bob, ['dead-token', 'live-token']), new SpyingDeadPushTokens(), new NullLogger());

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
        $sender = new ExpoPushSender(new Transports(['expo' => self::transportRejecting('dead-token', $bridgeMessage)]), self::targetsOf($bob, ['dead-token']), new SpyingDeadPushTokens(), new NullLogger());

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
        $sender = new ExpoPushSender(new Transports(['expo' => self::transportRejecting('dead-token', $networkFailure)]), self::targetsOf($bob, ['dead-token']), new SpyingDeadPushTokens(), new NullLogger());

        [$ticket] = $sender->send($bob, self::notification());

        self::assertSame(PushRejection::Unknown, $ticket->rejection);
        self::assertSame($networkFailure, $ticket->rawReason);
    }

    /**
     * Le cœur du #131 : le fournisseur est formel sur `DeviceNotRegistered`, donc le
     * jeton est effacé sèchement, sans attendre le reçu de livraison.
     */
    public function testADeviceNotRegisteredRejectionDiscardsTheDeadToken(): void
    {
        $bob = Uuid::v7();
        $deadPushTokens = new SpyingDeadPushTokens();
        $sender = new ExpoPushSender(new Transports(['expo' => self::transportRejecting('dead-token', self::bridgeMessage('DeviceNotRegistered'))]), self::targetsOf($bob, ['dead-token']), $deadPushTokens, new NullLogger());

        $sender->send($bob, self::notification());

        self::assertSame(['dead-token'], $deadPushTokens->discarded);
    }

    /**
     * Le #150 : le même refus que le test ci-dessus, mais à travers le **vrai**
     * `ExpoTransport` du bridge — `MockHttpClient` rendant la réponse d'erreur qu'Expo
     * produit réellement. Rouge sur `main` avant #150 : `ExpoPushSender` n'y accepte
     * qu'un `TexterInterface`, et un `ExpoTransport` ne l'implémente pas — le type ne
     * compile même pas. Ce test-là prouve que le refus traverse *ce* bridge sans lever :
     * `HandleMessageMiddleware` n'enveloppe plus le `TransportException` du bridge dans
     * un `HandlerFailedException` que le `catch` ci-dessous ne reconnaîtrait pas, parce
     * qu'`ExpoPushSender` n'appelle plus `Texter::send()` et son détour par le bus. Il ne
     * prouve pas, en revanche, que le câblage lui-même est protégé — qu'on ne puisse plus
     * brancher `'@texter'` à la place de `'@texter.transports'` — c'est le type
     * `Transports` du constructeur qui tient cette garantie-là, à la compilation du
     * conteneur, pas ce test.
     */
    public function testARealBridgeRejectionDiscardsTheDeadTokenWithoutThrowing(): void
    {
        $bob = Uuid::v7();
        $mockClient = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode([
            'data' => [
                'status' => 'error',
                'message' => 'The Expo push token is not a valid Expo push token.',
                'details' => ['error' => 'DeviceNotRegistered'],
            ],
        ], \JSON_THROW_ON_ERROR)));
        $deadPushTokens = new SpyingDeadPushTokens();
        $sender = new ExpoPushSender(new Transports(['expo' => new ExpoTransport(null, $mockClient)]), self::targetsOf($bob, ['dead-token']), $deadPushTokens, new NullLogger());

        [$ticket] = $sender->send($bob, self::notification());

        self::assertFalse($ticket->accepted);
        self::assertSame(PushRejection::DeviceNotRegistered, $ticket->rejection);
        self::assertSame(['dead-token'], $deadPushTokens->discarded);
    }

    /**
     * Le pendant obligatoire du test ci-dessus : `MessageRateExceeded` est un incident de
     * l'envoi, pas de l'appareil — confondre les deux effacerait des appareils vivants un
     * jour de panne. Même exigence pour `Unknown`, le refus qu'on ne sait pas qualifier.
     *
     * @return iterable<string, array{string}>
     */
    public static function nonFatalRejections(): iterable
    {
        yield 'MessageRateExceeded' => ['MessageRateExceeded'];
        yield 'MessageTooBig' => ['MessageTooBig'];
        yield 'InvalidCredentials' => ['InvalidCredentials'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonFatalRejections')]
    public function testANonFatalRejectionDoesNotDiscardTheToken(string $expoCode): void
    {
        $bob = Uuid::v7();
        $deadPushTokens = new SpyingDeadPushTokens();
        $sender = new ExpoPushSender(new Transports(['expo' => self::transportRejecting('token', self::bridgeMessage($expoCode))]), self::targetsOf($bob, ['token']), $deadPushTokens, new NullLogger());

        $sender->send($bob, self::notification());

        self::assertSame([], $deadPushTokens->discarded);
    }

    public function testAnUnrecognizedRejectionDoesNotDiscardTheToken(): void
    {
        $bob = Uuid::v7();
        $deadPushTokens = new SpyingDeadPushTokens();
        $sender = new ExpoPushSender(new Transports(['expo' => self::transportRejecting('token', 'Could not reach the remote Expo server.')]), self::targetsOf($bob, ['token']), $deadPushTokens, new NullLogger());

        $sender->send($bob, self::notification());

        self::assertSame([], $deadPushTokens->discarded);
    }

    /**
     * Le #144 : `groupingKey` et `route` voyagent tous les deux dans `data`, l'un à côté
     * de l'autre, jamais l'un à la place de l'autre — ils répondent à deux questions
     * différentes (voir le docblock de {@see PushRoute}).
     */
    public function testDataCarriesGroupingKeyAndRouteAlongsideEachOther(): void
    {
        $bob = Uuid::v7();
        $targetId = Uuid::v7();
        $texter = new SpyingTexter();
        $sender = new ExpoPushSender(new Transports(['expo' => $texter]), self::targetsOf($bob, ['token']), new SpyingDeadPushTokens(), new NullLogger());

        $notification = new PushNotification(
            'Titre',
            'Corps',
            NotificationCategory::GuildActivity,
            'guild-roster',
            new PushRoute(PushRouteType::PlayerProfile, $targetId),
        );

        $sender->send($bob, $notification);

        self::assertCount(1, $texter->sent);
        $options = $texter->sent[0]->getOptions();
        self::assertInstanceOf(ExpoOptions::class, $options);

        $data = $options->toArray()['data'];
        self::assertIsArray($data);
        self::assertSame('guild-roster', $data['groupingKey']);
        self::assertSame('PLAYER_PROFILE', $data['routeType']);
        self::assertSame($targetId->toRfc4122(), $data['routeId']);
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
        return new PushNotification('Un coéquipier a rejoint', 'Dis bonjour à Bob.', NotificationCategory::GuildActivity, 'guild-roster', new PushRoute(PushRouteType::PlayerProfile, Uuid::v7()));
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

    /**
     * Un transport qui refuse un jeton précis et accepte tous les autres — assez pour
     * prouver la tolérance de l'adapter, sans reconstruire tout `ExpoTransport`.
     */
    private static function transportRejecting(string $rejectedToken, string $reason): TransportInterface
    {
        return new class($rejectedToken, $reason) implements TransportInterface {
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
