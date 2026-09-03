<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Shared\Domain\Activity\Discipline;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** L'équilibrage d'une case fermée de Discipline, publié avec le reste du ruleset. */
#[ORM\Entity]
#[ORM\Table(name: 'game_discipline')]
#[ORM\UniqueConstraint(name: 'uniq_game_discipline', columns: ['discipline'])]
class GameDiscipline
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(enumType: Discipline::class, length: 30)]
    private Discipline $discipline;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order')]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'credits_xp')]
    private bool $creditsXp = true;

    #[ORM\Column(name: 'daily_cap_xp', nullable: true)]
    private ?int $dailyCapXp = null;

    #[ORM\Column(name: 'xp_per_km', nullable: true)]
    private ?int $xpPerKm = null;

    #[ORM\Column(name: 'xp_per_100m_elevation', nullable: true)]
    private ?int $xpPer100mElevation = null;

    /** @var array{strength: int, endurance: int, mobility: int, dexterity: int}|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $split = null;

    /** @var array{fr: array{label: string}, en: array{label: string}} */
    #[ORM\Column(type: Types::JSON)]
    private array $translations = ['fr' => ['label' => ''], 'en' => ['label' => '']];

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDiscipline(): Discipline
    {
        return $this->discipline;
    }

    public function setDiscipline(Discipline $discipline): void
    {
        $this->discipline = $discipline;
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

    public function creditsXp(): bool
    {
        return $this->creditsXp;
    }

    public function setCreditsXp(bool $creditsXp): void
    {
        $this->creditsXp = $creditsXp;
    }

    public function getDailyCapXp(): ?int
    {
        return $this->dailyCapXp;
    }

    public function setDailyCapXp(?int $dailyCapXp): void
    {
        $this->dailyCapXp = $dailyCapXp;
    }

    public function getXpPerKm(): ?int
    {
        return $this->xpPerKm;
    }

    public function setXpPerKm(?int $xpPerKm): void
    {
        $this->xpPerKm = $xpPerKm;
    }

    public function getXpPer100mElevation(): ?int
    {
        return $this->xpPer100mElevation;
    }

    public function setXpPer100mElevation(?int $xpPer100mElevation): void
    {
        $this->xpPer100mElevation = $xpPer100mElevation;
    }

    /** @return array{strength: int, endurance: int, mobility: int, dexterity: int}|null */
    public function getSplit(): ?array
    {
        return $this->split;
    }

    /** @param array{strength: int, endurance: int, mobility: int, dexterity: int}|null $split */
    public function setSplit(?array $split): void
    {
        $this->split = $split;
    }

    /** @return array{fr: array{label: string}, en: array{label: string}} */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /** @param array{fr: array{label: string}, en: array{label: string}} $translations */
    public function setTranslations(array $translations): void
    {
        $this->translations = $translations;
    }
}
