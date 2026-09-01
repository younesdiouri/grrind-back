<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'game_title')]
#[ORM\UniqueConstraint(name: 'uniq_game_title_key', columns: ['title_key'])]
class GameTitle
{
    #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;
    #[ORM\Column(name: 'title_key', length: 64)] private string $key;
    #[ORM\Column] private bool $active = true;
    #[ORM\Column(name: 'sort_order')] private int $sortOrder = 0;
    #[ORM\Column(name: 'condition_type', length: 40)] private string $conditionType = 'session_count';
    #[ORM\Column] private int $threshold = 1;
    #[ORM\Column(length: 30, nullable: true)] private ?string $discipline = null;
    /** @var array{fr: array{name: string, hint: string}, en: array{name: string, hint: string}} */
    #[ORM\Column(type: Types::JSON)] private array $translations = ['fr' => ['name' => '', 'hint' => ''], 'en' => ['name' => '', 'hint' => '']];
    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function __toString(): string
    {
        return $this->key ?? '';
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getConditionType(): string
    {
        return $this->conditionType;
    }

    public function setConditionType(string $conditionType): void
    {
        $this->conditionType = $conditionType;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function setThreshold(int $threshold): void
    {
        $this->threshold = $threshold;
    }

    public function getDiscipline(): ?string
    {
        return $this->discipline;
    }

    public function setDiscipline(?string $discipline): void
    {
        $this->discipline = $discipline;
    }

    /** @return array{fr: array{name: string, hint: string}, en: array{name: string, hint: string}} */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /** @param array{fr: array{name: string, hint: string}, en: array{name: string, hint: string}} $translations */
    public function setTranslations(array $translations): void
    {
        $this->translations = $translations;
    }
}
