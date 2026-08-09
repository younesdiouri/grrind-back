<?php

declare(strict_types=1);

namespace App\Identity\UI\Http\Response;

use App\Identity\Domain\User;
use DateTimeInterface;

/**
 * Représentation publique d'un compte. Séparée de l'entité pour que le jour où
 * celle-ci gagne un champ, il ne parte pas sur le réseau par accident.
 */
final readonly class UserResource
{
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public string $timezone,
        public string $registeredAt,
    ) {
    }

    public static function from(User $user): self
    {
        return new self(
            $user->id()->toRfc4122(),
            $user->email()->toString(),
            $user->displayName(),
            $user->timezone()->toString(),
            $user->registeredAt()->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'displayName' => $this->displayName,
            'timezone' => $this->timezone,
            'registeredAt' => $this->registeredAt,
        ];
    }
}
