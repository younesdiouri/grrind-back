<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SendChatMessageRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $clientId = '',
        public string $text = '',
    ) {
    }
}
