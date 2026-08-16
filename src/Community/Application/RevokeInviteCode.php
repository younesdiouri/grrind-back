<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Guild;

final readonly class RevokeInviteCode
{
    public function __construct(public Guild $guild)
    {
    }
}
