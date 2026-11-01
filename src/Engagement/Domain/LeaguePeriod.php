<?php

declare(strict_types=1);

namespace App\Engagement\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Le rendez-vous persistant permet au worker de rattraper une interruption sans rejouer une clôture. */
#[ORM\Entity]
#[ORM\Table(name: 'engagement_league_period')]
class LeaguePeriod
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $startsAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $endsAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        public ?DateTimeImmutable $settledAt = null,
        #[ORM\Column(length: 32, nullable: true)]
        public ?string $rulesetVersion = null,
    ) {
    }
}
