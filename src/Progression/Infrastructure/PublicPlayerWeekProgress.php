<?php

declare(strict_types=1);

namespace App\Progression\Infrastructure;

use App\Progression\Application\WeekProgressProvider;
use App\Progression\UI\Http\Response\WeekProgressResource;
use App\Shared\Application\PlayerWeekProgress;
use Symfony\Component\Uid\Uuid;

/** Même provider et même sérialisation que l'accueil, avec une liste explicite de champs publics. */
final readonly class PublicPlayerWeekProgress implements PlayerWeekProgress
{
    public function __construct(private WeekProgressProvider $weeks)
    {
    }

    public function of(Uuid $playerId): array
    {
        return WeekProgressResource::summary($this->weeks->of($playerId));
    }
}
