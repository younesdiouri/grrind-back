<?php

declare(strict_types=1);

namespace App\Community\Application;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Un ancien membre peut encore recevoir le signal jusqu'à expiration de son jeton.
 * Aucun contenu privé n'y figure : seule l'API, qui revalide l'adhésion, le délivre.
 */
#[AsMessageHandler]
final readonly class ChatChangedHandler
{
    public function __construct(private HubInterface $hub)
    {
    }

    public function __invoke(ChatChanged $signal): void
    {
        $this->hub->publish(new Update(self::topic($signal->guildId), '{"type":"chat.changed"}', true));
    }

    public static function topic(string $guildId): string
    {
        return 'https://grrind.app/guilds/'.$guildId.'/chat';
    }
}
