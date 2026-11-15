<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;
use Symfony\Component\Uid\Uuid;

final readonly class RevokeInviteCode
{
    public function __construct(
        public Guild $guild,
        public Uuid $actorId,
    ) {
    }
}
