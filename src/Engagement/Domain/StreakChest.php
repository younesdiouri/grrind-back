<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Le coffre d'une semaine pointée sept jours sur sept (#346), une fois pour toutes : la
 * ligne d'audit et la garantie d'unicité, par `(joueur, lundi)`.
 *
 * Écrit par `INSERT … ON CONFLICT DO NOTHING` dans {@see \App\Engagement\Application\StreakTracker} ;
 * l'entité n'existe que pour que le schéma reste décrit par le mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'engagement_streak_chest')]
#[ORM\UniqueConstraint(name: 'uniq_streak_chest_week', columns: ['user_id', 'week_start'])]
class StreakChest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    public function __construct(
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $userId,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private DateTimeImmutable $weekStart,
        #[ORM\Column]
        private int $sportDays,
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
}
