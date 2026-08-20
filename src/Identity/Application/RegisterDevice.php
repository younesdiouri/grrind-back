<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\DeviceEnvironment;
use App\Identity\Domain\DevicePlatform;
use Symfony\Component\Uid\Uuid;

final readonly class RegisterDevice
{
    public function __construct(
        public string $pushToken,
        public ?Uuid $familyId,
        public DevicePlatform $platform,
        public DeviceEnvironment $environment,
    ) {
    }
}
