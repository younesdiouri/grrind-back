<?php

declare(strict_types=1);

namespace App\Community\Application;

/** Signal interne placé dans l'outbox avec le message, sans contenu de conversation. */
final readonly class ChatChanged
{
    public function __construct(public string $guildId)
    {
    }
}
