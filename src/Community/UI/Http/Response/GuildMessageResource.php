<?php

declare(strict_types=1);

namespace App\Community\UI\Http\Response;

use App\Community\Domain\GuildMessage;
use DateTimeInterface;

final class GuildMessageResource
{
    /** @return array<string, string|null> */
    public static function from(GuildMessage $message): array
    {
        return [
            'id' => $message->id()->toRfc4122(),
            'clientId' => $message->clientId()->toRfc4122(),
            'cursor' => (string) $message->position(),
            'authorId' => $message->authorId()->toRfc4122(),
            'text' => $message->text(),
            'createdAt' => $message->createdAt()->format(DateTimeInterface::ATOM),
            'imageUrl' => null === $message->imageKey() ? null : '/api/guilds/'.$message->guild()->id()->toRfc4122().'/chat/messages/'.$message->id()->toRfc4122().'/image',
        ];
    }
}
