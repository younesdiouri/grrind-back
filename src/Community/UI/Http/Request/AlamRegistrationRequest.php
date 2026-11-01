<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AlamRegistrationRequest
{
    public function __construct(#[Assert\NotNull] public ?bool $registered = null)
    {
    }
}
