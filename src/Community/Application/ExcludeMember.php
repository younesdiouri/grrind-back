<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;
use Symfony\Component\Uid\Uuid;

/**
 * La guilde arrive déjà chargée et déjà autorisée — `#[VisibleGuild]` a rendu le 404,
 * `#[IsGranted(GUILD_KICK)]` le 403. Restent les deux identifiants : qui exclut, et qui
 * est exclu. Le premier vient du jeton.
 */
final readonly class ExcludeMember
{
    public function __construct(
        public Guild $guild,
        public Uuid $founderId,
        public Uuid $excludedId,
    ) {
    }
}
