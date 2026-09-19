<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;
use LogicException;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Les recettes restent des brouillons jusqu'à la publication atomique des règles. */
#[ORM\Entity]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'game_recipe')]
#[ORM\UniqueConstraint(name: 'uniq_game_recipe_key', columns: ['recipe_key'])]
class GameRecipe
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(name: 'recipe_key', length: 64)]
    private string $key = '';

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private bool $everPublishedActive = false;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column(length: 64)]
    private string $resultItem = '';

    #[ORM\Column]
    private int $quantity = 1;

    /** @var list<array{item: string, quantity: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $costs = [];

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return $this->key;
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    /** @return list<array{item: string, quantity: int}> */
    public function getCosts(): array
    {
        return $this->costs;
    }

    /** @param list<array{item: string, quantity: int}> $costs */
    public function setCosts(array $costs): void
    {
        $this->costs = array_values($costs);
    }

    /** @return array<string, int> */
    public function publishedCosts(): array
    {
        $result = [];
        foreach ($this->costs as $cost) {
            if (isset($result[$cost['item']])) {
                throw new LogicException('Une ressource ne peut figurer deux fois dans la même recette.');
            }
            $result[$cost['item']] = $cost['quantity'];
        }

        return $result;
    }

    #[ORM\PreUpdate]
    public function refuseKeyRename(PreUpdateEventArgs $event): void
    {
        if ($event->hasChangedField('key')) {
            throw new LogicException('La clé métier d’une recette est immuable.');
        }
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): void
    {
        $this->key = $key;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getResultItem(): string
    {
        return $this->resultItem;
    }

    public function setResultItem(string $resultItem): void
    {
        $this->resultItem = $resultItem;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }
}
