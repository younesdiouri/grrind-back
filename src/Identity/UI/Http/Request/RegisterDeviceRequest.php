<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Request;

use App\Identity\Domain\DeviceEnvironment;
use App\Identity\Domain\DevicePlatform;
use App\Identity\Domain\UserDevice;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Contrat d'entrée de `POST /api/devices`.
 *
 * `platform` et `environment` sont nullables avec un défaut nul plutôt que non nullables :
 * une valeur absente ou hors énumération donne un 422 nommant le champ au lieu d'une erreur
 * de dénormalisation opaque, même pattern que {@see \App\Training\UI\Http\Request\ImportedWorkoutRequest}.
 */
final readonly class RegisterDeviceRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: UserDevice::PUSH_TOKEN_MAX_LENGTH)]
        public string $pushToken = '',
        #[Assert\NotNull]
        public ?DevicePlatform $platform = null,
        #[Assert\NotNull]
        public ?DeviceEnvironment $environment = null,
    ) {
    }
}
