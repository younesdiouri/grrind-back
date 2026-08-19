<?php

declare(strict_types=1);

namespace App\Tests\Identity\Domain;

use App\Identity\Domain\DeviceEnvironment;
use App\Identity\Domain\DevicePlatform;
use App\Identity\Domain\User;
use App\Identity\Domain\UserDevice;
use App\Shared\Domain\Timezone;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * `claim()` porte trois usages avec une seule méthode : la création, le réenregistrement
 * anodin du même compte, et le changement de propriétaire. Voir le docblock de la classe
 * pour pourquoi les trois se confondent volontairement.
 */
final class UserDeviceTest extends TestCase
{
    public function testRegisteringStampsTheCurrentOwnerAndTimestamps(): void
    {
        $bob = self::user('bob@grrind.app');
        $now = new DateTimeImmutable('2026-08-19T09:00:00+00:00');

        $device = UserDevice::register('token-1', $bob, DevicePlatform::Ios, DeviceEnvironment::Production, $now);

        self::assertSame($bob, $device->user());
        self::assertSame(DevicePlatform::Ios, $device->platform());
        self::assertSame(DeviceEnvironment::Production, $device->environment());
        self::assertEquals($now, $device->registeredAt());
        self::assertEquals($now, $device->lastSeenAt());
    }

    public function testReclaimingBySameOwnerOnlyRefreshesLastSeenAt(): void
    {
        $bob = self::user('bob@grrind.app');
        $registeredAt = new DateTimeImmutable('2026-08-19T09:00:00+00:00');
        $device = UserDevice::register('token-1', $bob, DevicePlatform::Ios, DeviceEnvironment::Development, $registeredAt);

        $reregisteredAt = new DateTimeImmutable('2026-08-20T09:00:00+00:00');
        $device->claim($bob, DevicePlatform::Ios, DeviceEnvironment::Production, $reregisteredAt);

        self::assertSame($bob, $device->user());
        self::assertSame(DeviceEnvironment::Production, $device->environment());
        self::assertEquals($registeredAt, $device->registeredAt(), 'Le premier enregistrement ne bouge pas.');
        self::assertEquals($reregisteredAt, $device->lastSeenAt());
    }

    /**
     * Le piège du ticket #129 : un téléphone qui change de compte fait revenir le même
     * jeton avec un autre `userId`. La ligne doit changer de propriétaire, pas se
     * dupliquer — sinon l'ancien compte continue de recevoir les notifications d'un
     * appareil qui ne lui appartient plus.
     */
    public function testClaimingByAnotherAccountTransfersOwnership(): void
    {
        $bob = self::user('bob@grrind.app');
        $alice = self::user('alice@grrind.app');
        $now = new DateTimeImmutable('2026-08-19T09:00:00+00:00');

        $device = UserDevice::register('token-1', $bob, DevicePlatform::Ios, DeviceEnvironment::Production, $now);

        $later = new DateTimeImmutable('2026-08-21T09:00:00+00:00');
        $device->claim($alice, DevicePlatform::Android, DeviceEnvironment::Development, $later);

        self::assertSame($alice, $device->user());
        self::assertSame(DevicePlatform::Android, $device->platform());
        self::assertSame(DeviceEnvironment::Development, $device->environment());
    }

    private static function user(string $email): User
    {
        return User::register($email, 'Bob', Timezone::fromString('Europe/Paris'), new DateTimeImmutable('2026-08-10T09:00:00+02:00'));
    }
}
