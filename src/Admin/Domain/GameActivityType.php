<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use App\Shared\Domain\Activity\Discipline;
use App\Shared\Domain\Activity\WorkoutSource;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Une correspondance fournisseur est de l'équilibrage : elle décide ce qu'un effort crédite. */
#[ORM\Entity]
#[ORM\Table(name: 'game_activity_type')]
#[ORM\UniqueConstraint(name: 'uniq_game_activity_source_provider', columns: ['source', 'provider_type'])]
class GameActivityType
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(enumType: WorkoutSource::class, length: 30)]
    private WorkoutSource $source;

    #[ORM\Column(name: 'provider_type', length: 120)]
    private string $providerType;

    #[ORM\Column(enumType: Discipline::class, length: 30)]
    private Discipline $discipline;

    #[ORM\Column]
    private bool $active = true;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSource(): WorkoutSource
    {
        return $this->source;
    }

    public function setSource(WorkoutSource $source): void
    {
        $this->source = $source;
    }

    public function getProviderType(): string
    {
        return $this->providerType;
    }

    public function setProviderType(string $providerType): void
    {
        $this->providerType = $providerType;
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
}
