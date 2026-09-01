<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Les deux réglages globaux qui ne sont pas des collections : combattant et loot luck. */
#[ORM\Entity]
#[ORM\Table(name: 'game_settings')]
class GameSettings
{
    #[ORM\Id] #[ORM\Column] private int $id = 1;
    /** @var array<string, int> */ #[ORM\Column(type: Types::JSON)] private array $fighter = [];
    /** @var array{floor_percent: int, cap_percent: int} */ #[ORM\Column(name: 'loot_luck', type: Types::JSON)] private array $lootLuck = ['floor_percent' => 0, 'cap_percent' => 200];
    #[ORM\Column(name: 'loot_version')] private int $lootVersion = 1;
    public function __toString(): string
    {
        return 'Réglages globaux';
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

    public function lootVersion(): int
    {
        return $this->lootVersion;
    }

    public function incrementLootVersion(): void
    {
        ++$this->lootVersion;
    }
}
