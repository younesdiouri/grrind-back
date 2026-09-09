<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChatHistoryRequest
{
    public function __construct(
        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 50,
        #[Assert\Regex('/^[1-9][0-9]{0,8}$/D')]
        public ?string $before = null,
        #[Assert\Regex('/^(0|[1-9][0-9]{0,8})$/D')]
        public ?string $after = null,
    ) {
    }
}
