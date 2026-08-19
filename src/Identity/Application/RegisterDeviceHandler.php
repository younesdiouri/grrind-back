<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserDevice;
use App\Identity\Infrastructure\Doctrine\UserDeviceRepository;
use Psr\Clock\ClockInterface;

/**
 * Enregistre — ou réenregistre — l'appareil courant du compte.
 *
 * Une seule branche pour « premier enregistrement », « redémarrage habituel » et « ce
 * téléphone appartenait à un autre compte » : {@see UserDevice::claim()} porte la même
 * opération dans les trois cas, voir son docblock pour le pourquoi.
 */
final readonly class RegisterDeviceHandler
{
    public function __construct(
        private UserDeviceRepository $devices,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(User $user, RegisterDevice $command): UserDevice
    {
        $now = $this->clock->now();
        $device = $this->devices->ofPushToken($command->pushToken);

        if (null === $device) {
            $device = UserDevice::register($command->pushToken, $user, $command->platform, $command->environment, $now);
            $this->devices->add($device);
        } else {
            $device->claim($user, $command->platform, $command->environment, $now);
        }

        $this->devices->commit();

        return $device;
    }
}
