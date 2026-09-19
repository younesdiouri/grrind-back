<?php

declare(strict_types=1);

namespace App\Rewards\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CraftItemRequest
{
    public function __construct(#[Assert\NotBlank] #[Assert\Length(max: 64)] public string $recipeKey = '')
    {
    }
}
