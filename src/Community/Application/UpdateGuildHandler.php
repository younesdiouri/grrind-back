<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;
use App\Community\Infrastructure\Doctrine\GuildRepository;
use App\Community\Infrastructure\Security\GuildMutationAuthorization;
use App\Community\Infrastructure\Security\GuildVoter;

final readonly class UpdateGuildHandler
{
    public function __construct(
        private GuildRepository $guilds,
        private GuildMutationAuthorization $authorization,
    ) {
    }

    public function __invoke(UpdateGuild $command): Guild
    {
        return $this->guilds->transactional(function () use ($command): Guild {
            $guild = $this->authorization->lock($command->guild->id(), $command->actorId, GuildVoter::EDIT);
            if (null !== $command->name) {
                $guild->rename($command->name);
            }
            if (null !== $command->emblem) {
                $guild->changeEmblem($command->emblem);
            }

            $this->guilds->commit();

            return $guild;
        });
    }
}
