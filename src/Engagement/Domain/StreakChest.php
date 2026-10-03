<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le coffre d'une semaine de régularité, une fois pour toutes (#286) : la ligne d'audit
 * et la garantie d'unicité. Une série se reconnaît à son premier jour.
 *
 * ponytail: un import tardif qui comble le trou entre deux séries les fusionne et déplace
 * l'ancre — les semaines de la série fusionnée peuvent alors rapporter un second coffre.
 * Borné par la fenêtre d'antériorité de 30 jours ; clé par jour de clôture si ça compte.
 */
#[ORM\Entity]
#[ORM\Table(name: 'engagement_streak_chest')]
#[ORM\UniqueConstraint(name: 'uniq_streak_chest_week', columns: ['user_id', 'run_started_on', 'week'])]
class StreakChest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $userId,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $runStartedOn,
        #[ORM\Column]
        private int $week,
        #[ORM\Column(length: 20)]
        private string $rarity,
        #[ORM\Column(length: 64)]
        private string $itemKey,
        #[ORM\Column(length: 64)]
        private string $rulesetVersion,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $grantedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function week(): int
    {
        return $this->week;
    }
}
