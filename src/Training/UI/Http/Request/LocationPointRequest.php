<?php

declare(strict_types=1);

namespace App\Training\UI\Http\Request;

use DateTimeImmutable;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class LocationPointRequest
{
    public function __construct(
        #[Assert\NotNull]
        public ?DateTimeImmutable $timestamp = null,
        #[Assert\NotNull]
        #[Assert\Range(min: -90, max: 90)]
        public ?float $latitude = null,
        #[Assert\NotNull]
        #[Assert\Range(min: -180, max: 180)]
        public ?float $longitude = null,
        #[Assert\NotNull]
        #[Assert\Range(min: 0, max: 10000)]
        public ?float $horizontalAccuracy = null,
    ) {
    }
}
