<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Request;

use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SendChatMessageRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public string $clientId = '',
        public string $text = '',
        #[OA\Property(type: 'string', format: 'binary', nullable: true)]
        public ?UploadedFile $image = null,
    ) {
    }
}
