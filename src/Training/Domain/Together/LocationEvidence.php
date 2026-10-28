<?php

declare(strict_types=1);

namespace App\Training\Domain\Together;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'location_evidence')]
#[ORM\Index(name: 'idx_evidence_provider', columns: ['user_id', 'source', 'external_id'])]
#[ORM\Index(name: 'idx_evidence_expiry', columns: ['ended_at'])]
class LocationEvidence
{
    /** @param list<array{at: int, latitude: float, longitude: float, accuracy: float}> $points */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $userId,
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        public Uuid $batchId,
        #[ORM\Column(length: 32)]
        public string $source,
        #[ORM\Column(length: 128)]
        public string $externalId,
        #[ORM\Column(length: 32)]
        public string $origin,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $startedAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $endedAt,
        #[ORM\Column(type: Types::JSON)]
        public array $points,
        #[ORM\Column(length: 64)]
        public string $digest,
    ) {
    }
}
