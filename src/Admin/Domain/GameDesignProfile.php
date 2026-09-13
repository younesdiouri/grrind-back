<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * L'XP initiale est la somme des attributs, jamais un niveau contradictoire saisi à part.
 * Les bornes sont des limites de calcul du laboratoire, pas des règles de progression.
 *
 * @phpstan-type ProfileInput array{name: string, strength: int, endurance: int, mobility: int, dexterity: int, vitalityOverride: ?int, equipment: list<string>}
 */
#[ORM\Entity]
#[ORM\Table(name: 'game_design_profile')]
class GameDesignProfile
{
    #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] private Uuid $id;
    #[ORM\Column(length: 80)] #[Assert\NotBlank] #[Assert\Length(max: 80)] private string $name = '';
    #[ORM\Column] #[Assert\Range(min: 0, max: 100000000)] private int $strength = 0;
    #[ORM\Column] #[Assert\Range(min: 0, max: 100000000)] private int $endurance = 0;
    #[ORM\Column] #[Assert\Range(min: 0, max: 100000000)] private int $mobility = 0;
    #[ORM\Column] #[Assert\Range(min: 0, max: 100000000)] private int $dexterity = 0;
    #[ORM\Column(nullable: true)] #[Assert\Range(min: 0, max: 1000000000)] private ?int $vitalityOverride = null;
    /** @var list<string> */ #[ORM\Column(type: Types::JSON)] private array $equipment = [];
    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): void
    {
        $this->name = $value;
    }

    public function getStrength(): int
    {
        return $this->strength;
    }

    public function setStrength(int $value): void
    {
        $this->strength = $value;
    }

    public function getEndurance(): int
    {
        return $this->endurance;
    }

    public function setEndurance(int $value): void
    {
        $this->endurance = $value;
    }

    public function getMobility(): int
    {
        return $this->mobility;
    }

    public function setMobility(int $value): void
    {
        $this->mobility = $value;
    }

    public function getDexterity(): int
    {
        return $this->dexterity;
    }

    public function setDexterity(int $value): void
    {
        $this->dexterity = $value;
    }

    public function getVitalityOverride(): ?int
    {
        return $this->vitalityOverride;
    }

    public function setVitalityOverride(?int $value): void
    {
        $this->vitalityOverride = $value;
    }

    /** @return list<string> */
    public function getEquipment(): array
    {
        return $this->equipment;
    }

    /** @param list<string> $value */
    public function setEquipment(array $value): void
    {
        $this->equipment = $value;
    }

    /** @return ProfileInput */
    public function input(): array
    {
        return ['name' => $this->name, 'strength' => $this->strength, 'endurance' => $this->endurance, 'mobility' => $this->mobility, 'dexterity' => $this->dexterity, 'vitalityOverride' => $this->vitalityOverride, 'equipment' => $this->equipment];
    }
}
