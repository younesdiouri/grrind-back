<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;
use App\Community\Domain\GuildEmblem;
use Symfony\Component\Uid\Uuid;

/**
 * L'autorisation HTTP peut précéder une succession : le handler la rejoue sous verrou
 * pour cet acteur, avec le même voter.
 */
final readonly class UpdateGuild
{
    public function __construct(
        public Guild $guild,
        public Uuid $actorId,
        public ?string $name = null,
        public ?GuildEmblem $emblem = null,
    ) {
    }
}
