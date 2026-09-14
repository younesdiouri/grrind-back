<?php

declare(strict_types=1);

namespace App\Rewards\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** Chaque attribution est unique même quand la résolution d'une édition est reprise. */
#[ORM\Entity]
#[ORM\Table(name: 'rewards_alam_grant')]
#[ORM\UniqueConstraint(name: 'uniq_alam_grant_origin', columns: ['run_id', 'encounter', 'user_id'])]
class AlamGrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    /** @param array<string, int> $items */
    public function __construct(
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $runId,
        #[ORM\Column]
        private int $encounter,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $userId,
        #[ORM\Column(type: Types::JSON)]
        private array $items,
        #[ORM\Column(length: 64)]
        private string $rulesetVersion,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private DateTimeImmutable $occurredAt,
    ) {
        $this->id = Uuid::v7();
    }
}
