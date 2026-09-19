<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AlamHistoryQuery
{
    public function __construct(#[Assert\Range(min: 1, max: 50)] public int $limit = 20, public ?string $cursor = null)
    {
    }
}
