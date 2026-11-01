<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Uid\Uuid;

/** Identité de ligue batch pour les profils, sans import d'Engagement depuis Identity ou Community. */
interface PlayerLeagues
{
    /**
     * @param list<Uuid> $players
     *
     * @return array<string, array{tier: string, tierOrder: int, lootLuckPercent: int}>
     */
    public function of(array $players): array;
}
