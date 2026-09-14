<?php

declare(strict_types=1);

namespace App\Community\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** L'édition fige les règles à la révélation puis le roster et les faits au COMMIT de résolution. */
#[ORM\Entity]
#[ORM\Table(name: 'community_alam_run')]
#[ORM\UniqueConstraint(name: 'uniq_alam_week', columns: ['guild_id', 'week_starts_at'], options: ['where' => "((mode)::text = 'WEEKLY'::text)"])]
#[ORM\UniqueConstraint(name: 'uniq_alam_request', columns: ['request_key'])]
#[ORM\Index(name: 'idx_alam_guild_id', columns: ['guild_id', 'id'])]
class AlamRun
{
    #[ORM\Id] #[ORM\Column(type: UuidType::NAME)] public private(set) Uuid $id;
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)] public ?DateTimeImmutable $resolvedAt = null;
    /** @var array<string, mixed> */ #[ORM\Column(type: Types::JSON)] public array $result = [];
    #[ORM\Column(length: 64)] public private(set) string $seed;
    #[ORM\Column(length: 64, nullable: true)] public ?string $requestKey = null;
    /** @param array<string, mixed> $rules */
    public function __construct(
        #[ORM\Column(type: UuidType::NAME)]
        public private(set) Uuid $guildId,
        #[ORM\Column(length: 16, enumType: AlamMode::class)]
        public private(set) AlamMode $mode,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public private(set) DateTimeImmutable $weekStartsAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public private(set) DateTimeImmutable $collectionEndsAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public private(set) DateTimeImmutable $revealedAt,
        #[ORM\Column]
        public private(set) int $frozenTargetCount,
        #[ORM\Column(length: 32)]
        public private(set) string $rulesetVersion,
        #[ORM\Column(type: Types::JSON)]
        public private(set) array $rules,
    ) {
        $this->id = Uuid::v7();
        $this->seed = bin2hex(random_bytes(32));
    }
}
