<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Les réglages globaux non représentés par une ligne de catalogue. */
#[ORM\Entity]
#[ORM\Table(name: 'game_settings')]
class GameSettings
{
    #[ORM\Id] #[ORM\Column] private int $id = 1;
    /** @var array<string, int> */ #[ORM\Column(type: Types::JSON)] private array $fighter = [];
    /** @var array{floor_percent: int, cap_percent: int} */ #[ORM\Column(name: 'loot_luck', type: Types::JSON)] private array $lootLuck = ['floor_percent' => 0, 'cap_percent' => 200];
    /** @var array<string, mixed> */ #[ORM\Column(type: Types::JSON)] private array $training = [];
    /** @var array<string, mixed> */ #[ORM\Column(type: Types::JSON)] private array $xp = [];
    /** @var array<string, mixed> */ #[ORM\Column(type: Types::JSON)] private array $attributes = [];
    /** @var array<string, mixed> */ #[ORM\Column(type: Types::JSON)] private array $community = [];
    /** @var array<string, mixed> */ #[ORM\Column(type: Types::JSON)] private array $notifications = [];
    #[ORM\Column(name: 'loot_version')] private int $lootVersion = 1;
    public function __toString(): string
    {
        return 'Réglages globaux';
    }

    public function getId(): int
    {
        return $this->id;
    }

    /** @return array<string, int> */
    public function getFighter(): array
    {
        return $this->fighter;
    }

    /** @param array<string, int> $fighter */
    public function setFighter(array $fighter): void
    {
        $this->fighter = $fighter;
    }

    /** @return array{floor_percent: int, cap_percent: int} */
    public function getLootLuck(): array
    {
        return $this->lootLuck;
    }

    /** @param array{floor_percent: int, cap_percent: int} $lootLuck */
    public function setLootLuck(array $lootLuck): void
    {
        $this->lootLuck = $lootLuck;
    }

    /** @return array<string, mixed> */
    public function getTraining(): array
    {
        return $this->training;
    }

    /** @param array<string, mixed> $training */
    public function setTraining(array $training): void
    {
        $this->training = $training;
    }

    /** @return array<string, mixed> */
    public function getXp(): array
    {
        return $this->xp;
    }

    /** @param array<string, mixed> $xp */
    public function setXp(array $xp): void
    {
        $this->xp = $xp;
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /** @param array<string, mixed> $attributes */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    /** @return array<string, mixed> */
    public function getCommunity(): array
    {
        return $this->community;
    }

    /** @param array<string, mixed> $community */
    public function setCommunity(array $community): void
    {
        $this->community = $community;
    }

    /** @return array<string, mixed> */
    public function getNotifications(): array
    {
        return $this->notifications;
    }

    /** @param array<string, mixed> $notifications */
    public function setNotifications(array $notifications): void
    {
        $this->notifications = $notifications;
    }

    public function lootVersion(): int
    {
        return $this->lootVersion;
    }

    public function incrementLootVersion(): void
    {
        ++$this->lootVersion;
    }
}
