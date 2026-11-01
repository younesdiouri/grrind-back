<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use App\Shared\Domain\Alam\AlamDifficulty;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AlamDifficultyRequest
{
    public function __construct(#[Assert\NotNull] public ?AlamDifficulty $difficulty = null)
    {
    }
}
