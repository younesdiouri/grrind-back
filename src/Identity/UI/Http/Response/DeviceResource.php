<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Response;

use App\Identity\Domain\UserDevice;
use DateTimeInterface;

/**
 * Représentation publique d'un appareil enregistré. Ne porte jamais le jeton en retour :
 * le client vient de l'envoyer, il n'a rien à en apprendre, et un jeton qui revient dans
 * une réponse HTTP est un jeton qui finit dans des logs.
 */
final readonly class DeviceResource
{
    public function __construct(
        public string $id,
        public string $platform,
        public string $environment,
        public string $registeredAt,
        public string $lastSeenAt,
    ) {
    }

    public static function from(UserDevice $device): self
    {
        return new self(
            $device->id()->toRfc4122(),
            $device->platform()->value,
            $device->environment()->value,
            $device->registeredAt()->format(DateTimeInterface::ATOM),
            $device->lastSeenAt()->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'environment' => $this->environment,
            'registeredAt' => $this->registeredAt,
            'lastSeenAt' => $this->lastSeenAt,
        ];
    }
}
