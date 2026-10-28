<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use App\Shared\Domain\Activity\WorkoutSource;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LocationEvidenceRequest
{
    /** @param list<LocationPointRequest> $points */
    public function __construct(
        #[Assert\NotNull]
        public ?Uuid $batchId = null,
        #[Assert\NotNull]
        public ?WorkoutSource $source = null,
        #[Assert\NotBlank]
        #[Assert\Length(max: 128)]
        public string $externalId = '',
        #[Assert\Choice(choices: ['WORKOUT_ROUTE', 'PHONE_CAPTURE'])]
        public string $origin = '',
        #[Assert\NotNull]
        public ?DateTimeImmutable $startedAt = null,
        #[Assert\NotNull]
        public ?DateTimeImmutable $endedAt = null,
        #[Assert\Count(min: 1, max: 2000)]
        #[Assert\Valid]
        public array $points = [],
    ) {
    }
}
