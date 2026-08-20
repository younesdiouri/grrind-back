<?php

declare(strict_types=1);

namespace App\Tests\Shared\Infrastructure\Notifier;

use App\Shared\Application\PushNotification;
use App\Shared\Application\PushRoute;
use App\Shared\Application\PushSender;
use App\Shared\Domain\NotificationCategory;
use App\Shared\Domain\PushRouteType;
use App\Tests\Support\ApiTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Le câblage du port {@see PushSender} depuis le vrai conteneur — c'est ce test-là qui
 * prouve que `EXPO_DSN` résout vers `null://null` en environnement `test`, plutôt que
 * de le supposer depuis `config/packages/notifier.yaml`. Même rôle que
 * `PushTargetsTest` pour son voisin : le seul test qui prouve ce câblage précis.
 *
 * Il ne mocke rien : `self::getContainer()->get(PushSender::class)` est la chaîne réelle —
 * {@see \App\Shared\Infrastructure\Notifier\ReceiptSchedulingPushSender} (#131) décorant le
 * vrai `ExpoPushSender`, avec le vrai `TexterInterface` compilé du conteneur. S'il envoyait
 * réellement sur le réseau, ce test le ferait — c'est la garantie qu'aucune suite
 * PHPUnit ne peut appeler Expo, prouvée plutôt qu'affirmée. Le transport nul ne produit
 * jamais de `ticketId` (voir `ExpoPushSenderTest`), donc le décorateur n'a jamais rien à
 * programmer ici — c'est `ReceiptSchedulingPushSenderTest` qui le prouve, avec un sender
 * interne fabriqué pour l'occasion.
 */
final class PushSenderWiringTest extends ApiTestCase
{
    public function testResolvesFromTheContainerAndSendsWithoutReachingTheNetwork(): void
    {
        $bob = $this->openAccount();

        $this->post('/api/devices', [
            'pushToken' => 'wiring-token',
            'platform' => 'IOS',
            'environment' => 'DEVELOPMENT',
        ], $bob->headers);

        $sender = self::getContainer()->get(PushSender::class);
        self::assertInstanceOf(PushSender::class, $sender);

        $tickets = $sender->send($bob->id, new PushNotification('Titre', 'Corps', NotificationCategory::GuildActivity, 'wiring', new PushRoute(PushRouteType::PlayerProfile, Uuid::v7())));

        self::assertCount(1, $tickets);
        self::assertSame('wiring-token', $tickets[0]->pushToken);
        self::assertTrue($tickets[0]->accepted);
    }

    public function testAPlayerWithNoDeviceGetsNoTicket(): void
    {
        $bob = $this->openAccount();

        $sender = self::getContainer()->get(PushSender::class);
        self::assertInstanceOf(PushSender::class, $sender);

        self::assertSame([], $sender->send($bob->id, new PushNotification('Titre', 'Corps', NotificationCategory::GuildActivity, 'wiring', new PushRoute(PushRouteType::PlayerProfile, Uuid::v7()))));
    }
}
