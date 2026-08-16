<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;
use App\Community\Infrastructure\Doctrine\GuildRepository;

final readonly class RenameGuildHandler
{
    public function __construct(private GuildRepository $guilds)
    {
    }

    public function __invoke(RenameGuild $command): Guild
    {
        $command->guild->rename($command->name);

        $this->guilds->commit();

        return $command->guild;
    }
}
