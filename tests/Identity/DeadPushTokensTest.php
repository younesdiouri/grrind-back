<?php

declare(strict_types=1);

namespace App\Tests\Identity;

use App\Shared\Application\DeadPushTokens;
use App\Shared\Application\PushTargets;
use App\Shared\Domain\NotificationCategory;
use App\Tests\Support\ApiTestCase;

/**
 * Le câblage du port {@see DeadPushTokens} — comme {@see PushTargetsTest} pour son
 * voisin. Ce qui est vérifié précisément : `DeviceNotRegistered` efface la ligne, et rien
 * d'autre ne le fait — voir le docblock de {@see \App\Shared\Application\PushRejection}
 * pour pourquoi les deux ne se confondent pas.
 */
final class DeadPushTokensTest extends ApiTestCase
{
    public function testDiscardingARegisteredTokenRemovesIt(): void
    {
        $bob = $this->openAccount();

        $this->post('/api/devices', [
            'pushToken' => 'dead-token',
            'platform' => 'IOS',
            'environment' => 'PRODUCTION',
        ], $bob->headers);

        $deadPushTokens = self::getContainer()->get(DeadPushTokens::class);
        self::assertInstanceOf(DeadPushTokens::class, $deadPushTokens);
        $deadPushTokens->discard('dead-token');

        $targets = self::getContainer()->get(PushTargets::class);
        self::assertInstanceOf(PushTargets::class, $targets);
        self::assertSame([], $targets->of($bob->id, NotificationCategory::GuildActivity));
    }

    /**
     * Un jeton d'un autre appareil du même joueur n'est pas touché — la suppression porte
     * sur le jeton, jamais sur le compte.
     */
    public function testDiscardingOneTokenLeavesTheOthersOfTheSamePlayerIntact(): void
    {
        $bob = $this->openAccount();

        $this->post('/api/devices', [
            'pushToken' => 'dead-token',
            'platform' => 'IOS',
            'environment' => 'PRODUCTION',
        ], $bob->headers);

        $this->post('/api/devices', [
            'pushToken' => 'live-token',
            'platform' => 'ANDROID',
            'environment' => 'PRODUCTION',
        ], $bob->headers);

        $deadPushTokens = self::getContainer()->get(DeadPushTokens::class);
        self::assertInstanceOf(DeadPushTokens::class, $deadPushTokens);
        $deadPushTokens->discard('dead-token');

        $targets = self::getContainer()->get(PushTargets::class);
        self::assertInstanceOf(PushTargets::class, $targets);
        self::assertSame(['live-token'], $targets->of($bob->id, NotificationCategory::GuildActivity));
    }

    /**
     * Un jeton déjà parti (envoi concurrent, ou jamais enregistré) n'est pas une erreur —
     * voir le docblock du port.
     */
    public function testDiscardingAnUnknownTokenIsSilent(): void
    {
        $deadPushTokens = self::getContainer()->get(DeadPushTokens::class);
        self::assertInstanceOf(DeadPushTokens::class, $deadPushTokens);

        $deadPushTokens->discard('never-registered');

        $this->addToAssertionCount(1);
    }
}
