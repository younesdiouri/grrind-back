<?php

declare(strict_types=1);

namespace App\Community\Application;

/** L'empreinte porte sur l'upload original, pour rester stable si l'encodeur évolue. */
final readonly class PreparedChatImage
{
    public function __construct(public string $contents, public string $fingerprint)
    {
    }
}
