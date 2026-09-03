<?php

declare(strict_types=1);

namespace App\Admin\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Un palier explicite rend la courbe retouchable sans enfouir une formule dans le runtime. */
#[ORM\Entity]
#[ORM\Table(name: 'game_level')]
#[ORM\UniqueConstraint(name: 'uniq_game_level', columns: ['level'])]
class GameLevel
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column]
    private int $level = 1;

    #[ORM\Column(name: 'total_xp')]
    private int $totalXp = 0;

    #[ORM\Column(name: 'skill_points')]
    private int $skillPoints = 0;

    public function __construct()
    {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): void
    {
        $this->level = $level;
    }

    public function getTotalXp(): int
    {
        return $this->totalXp;
    }

    public function setTotalXp(int $totalXp): void
    {
        $this->totalXp = $totalXp;
    }

    public function getSkillPoints(): int
    {
        return $this->skillPoints;
    }

    public function setSkillPoints(int $skillPoints): void
    {
        $this->skillPoints = $skillPoints;
    }
}
