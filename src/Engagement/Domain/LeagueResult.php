<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** #35 : une semaine clôturée est figée. Les imports tardifs gardent leur XP sans réécrire les promotions. */
#[ORM\Entity]
#[ORM\Table(name: 'engagement_league_result')]
class LeagueResult
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $playerId,
        #[ORM\Id]
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $startsAt,
        #[ORM\Column(length: 16, enumType: LeagueTier::class)]
        public LeagueTier $tier,
        #[ORM\Column(length: 16, enumType: LeagueTier::class)]
        public LeagueTier $nextTier,
        #[ORM\Column]
        public int $score,
        #[ORM\Column(nullable: true)]
        public ?int $rank,
        #[ORM\Column]
        public int $participantCount,
    ) {
    }
}
