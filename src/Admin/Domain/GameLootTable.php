<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'game_loot_table')]
#[ORM\UniqueConstraint(name: 'uniq_game_loot_table_kind_key', columns: ['table_kind', 'table_key'])]
class GameLootTable
{
    #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;
    #[ORM\Column(name: 'table_kind', length: 20)] private string $kind = 'workout';
    #[ORM\Column(name: 'table_key', length: 64)] private string $key;
    #[ORM\Column] private bool $active = true;
    #[ORM\Column(name: 'ever_published_active')] private bool $everPublishedActive = false;
    #[ORM\Column(name: 'sort_order')] private int $sortOrder = 0;
    /** @var array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)] private ?array $eligibility = null;
    #[ORM\Column(name: 'coins_minimum')] private int $coinsMinimum = 0;
    #[ORM\Column(name: 'coins_maximum')] private int $coinsMaximum = 0;
    /** @var list<array{item?: string|null, weight: int}> */
    #[ORM\Column(type: Types::JSON)] private array $entries = [];
    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return $this->kind.':'.($this->key ?? '');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): void
    {
        $this->kind = $kind;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function wasEverPublishedActive(): bool
    {
        return $this->everPublishedActive;
    }

    public function markPublishedActive(): void
    {
        $this->everPublishedActive = true;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    /** @return array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}|null */
    public function getEligibility(): ?array
    {
        return $this->eligibility;
    }

    /** @param array{disciplines: list<string>, minimum_duration_minutes: int, minimum_level: int}|null $eligibility */
    public function setEligibility(?array $eligibility): void
    {
        $this->eligibility = $eligibility;
    }

    public function getCoinsMinimum(): int
    {
        return $this->coinsMinimum;
    }

    public function setCoinsMinimum(int $value): void
    {
        $this->coinsMinimum = $value;
    }

    public function getCoinsMaximum(): int
    {
        return $this->coinsMaximum;
    }

    public function setCoinsMaximum(int $value): void
    {
        $this->coinsMaximum = $value;
    }

    /** @return list<array{item?: string|null, weight: int}> */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /** @param list<array{item?: string|null, weight: int}> $entries */
    public function setEntries(array $entries): void
    {
        $this->entries = $entries;
    }

    #[ORM\PreUpdate]
    public function refuseIdentityRename(PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('key') || $event->hasChangedField('kind')) {
            throw new LogicException('Le couple nature et clé d’une table de loot est immuable après sa création.');
        }
    }
}
